"""Reconcile only the SpeedBox operator firewall table on a managed host."""

import fcntl
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

TABLE = "speedbox_operators"
ROOT = Path("/etc/speedbox-operators")
PROFILE = ROOT / "rules.nft"
UNIT_NAME = "speedbox-operator-firewall.service"
UNIT = Path("/etc/systemd/system") / UNIT_NAME
OWNER = "# Managed by SpeedBox operator filtering\n"
COMMENT = "speedbox:operator-firewall"
RESET = f"add table inet {TABLE}\ndelete table inet {TABLE}\n"
MODES = {"direct", "backpack", "gost", "gre", "wattunnel"}


def run(args, *, check=True, source=None):
    result = subprocess.run(args, input=source, text=True, capture_output=True, timeout=12)
    if check and result.returncode:
        raise RuntimeError(f"{args[0]}: {result.stderr.strip() or 'command failed'}")
    return result


def write(path, data):
    fd, tmp = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, "w") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)
    finally:
        Path(tmp).unlink(missing_ok=True)


def check_listeners(mode, protocol, port, ips, sockets):
    expected = {
        "direct": ("xboard-node",),
        "backpack": ("backpack",),
        "gost": ("gost", "speedbox-origin"),
        "gre": (),
        "wattunnel": ("wattun", "speedbox-origin"),
    }[mode]
    for row in sockets:
        parts = row.split(maxsplit=6)
        if len(parts) < 6 or parts[0] != protocol:
            continue
        address, separator, number = parts[4].rpartition(":")
        if not separator or number != str(port):
            continue
        address = address.strip("[]").split("%")[0]
        if address not in {"*", "0.0.0.0", "::"}:
            try:
                parsed = ipaddress.ip_address(address)
                ip = getattr(parsed, "ipv4_mapped", None) or parsed
            except ValueError:
                raise RuntimeError("Cannot identify a listener on the selected port")
            if ip not in ips:
                continue
        owners = re.findall(r'\("([^"]+)",pid=\d+,fd=\d+\)', parts[6] if len(parts) > 6 else "")
        if not owners or any(not name.startswith(expected) for name in owners):
            raise RuntimeError("Another service shares the selected port")


def render(payload, local_ips, sockets):
    rules = payload["rules"]
    policies = []
    needed = set()
    for node, policy in rules.items():
        port = policy["port"]
        if type(port) is not int or not 1 <= port <= 65535 or port in {22, payload["ssh_port"]}:
            raise ValueError("invalid or protected port")
        ips = {ipaddress.ip_address(ip) for ip in policy["addresses"]}
        if not ips or not ips.issubset(local_ips) or any(ip.is_loopback or ip.is_multicast for ip in ips):
            raise ValueError("The advertised address does not belong to this host")
        protocols = policy["protocols"]
        operators = policy["operators"]
        mode = policy["mode"]
        if (not protocols or not operators or mode not in MODES
                or set(protocols) - {"tcp", "udp"} or set(operators) - {"mci", "irancell"}):
            raise ValueError("invalid policy")
        for protocol in protocols:
            check_listeners(mode, protocol, port, ips, sockets)
        needed.update(operators)
        policies.append((node, port, ips, protocols, operators))

    sets = {}
    result = [OWNER.rstrip(), RESET.rstrip(), f"table inet {TABLE} {{", f'  comment "{COMMENT}"']
    for op in sorted(needed):
        nets = [ipaddress.ip_network(cidr) for cidr in payload["prefixes"][op]]
        for version in (4, 6):
            family = "ipv4" if version == 4 else "ipv6"
            ranges = list(ipaddress.collapse_addresses(net for net in nets if net.version == version))
            if not ranges:
                if version == 4:
                    raise ValueError("missing IPv4 operator ranges")
                continue
            if any(net.prefixlen < (8 if version == 4 else 16) for net in ranges):
                raise ValueError("operator range too broad")
            name = f"{op}_{version}"
            sets[op, version] = name
            elements = ", ".join(map(str, ranges))
            result.append(f"  set {name} {{ type {family}_addr; flags interval; elements = {{ {elements} }}; }}")

    # Match the public destination before DNAT, independently of local vs forwarded traffic.
    result.append("  chain incoming { type filter hook prerouting priority -110; policy accept;")
    for node, port, ips, protocols, operators in policies:
        for protocol in protocols:
            for version, family in ((4, "ip"), (6, "ip6")):
                targets = ", ".join(sorted(str(ip) for ip in ips if ip.version == version))
                if not targets:
                    continue
                for op in operators:
                    name = sets.get((op, version))
                    if name:
                        result.append(f"    {family} daddr {{ {targets} }} {family} saddr @{name} "
                                      f'{protocol} dport {port} counter drop comment "node {int(node)} {op}"')
    result.extend(["  }", "}", ""])
    return "\n".join(result)


def drop_matches(entry):
    expressions = entry.get("rule", {}).get("expr", [])
    if not any("drop" in expr for expr in expressions):
        return []
    return [expr["match"] for expr in expressions if "match" in expr and expr["match"].get("op") in {"==", "in"}]


def match_values(value):
    return value.get("set", []) if isinstance(value, dict) else [value]


def manual_protocols(entries, port, protocols):
    blocked = set()
    for entry in entries:
        matches = drop_matches(entry)
        if not any(match.get("left", {}).get("payload", {}).get("field") == "saddr"
                   and match.get("right") in ("@mci_v4", "@mci_v6") for match in matches):
            continue
        for match in matches:
            field = match.get("left", {}).get("payload", {})
            if field.get("field") == "dport" and field.get("protocol") in protocols:
                if port in match_values(match.get("right")):
                    blocked.add(field["protocol"])
    return sorted(blocked)


def table_exists(tables, name):
    return any(row.get("table", {}).get("name") == name
               and row["table"].get("family") == "inet" for row in tables)


def inspect_manual(port, protocols):
    nft = shutil.which("nft")
    if not nft:
        raise FileNotFoundError("operator-firewall-tools-missing")
    tables = json.loads(run([nft, "-j", "list", "tables"]).stdout)["nftables"]
    if not table_exists(tables, "mci_block"):
        return []
    entries = json.loads(run([nft, "-j", "list", "chain", "inet", "mci_block", "input"]).stdout)["nftables"]
    return manual_protocols(entries, port, protocols)


def managed_blocks(entries, node, target):
    owned = any(row.get("table", {}).get("comment") == COMMENT for row in entries)
    incoming = any(row.get("chain", {}).get("name") == "incoming"
                   and row["chain"].get("hook") == "prerouting" for row in entries)
    if not owned or not incoming:
        return []
    sets = {row["set"]["name"] for row in entries if "set" in row and row["set"].get("elem")}
    coverage = {"mci": set(), "irancell": set()}
    for row in entries:
        rule = row.get("rule", {})
        if rule.get("chain") != "incoming":
            continue
        comment = rule.get("comment", "").split()
        if len(comment) != 3 or comment[:2] != ["node", node] or comment[2] not in coverage:
            continue
        operator = comment[2]
        matches = {(m.get("left", {}).get("payload", {}).get("protocol"),
                    m.get("left", {}).get("payload", {}).get("field")): m["right"]
                   for m in drop_matches(row)}
        for family, version in (("ip", 4), ("ip6", 6)):
            subnet = f"{operator}_{version}"
            if subnet not in sets or matches.get((family, "saddr")) != "@" + subnet:
                continue
            for protocol in target["protocols"]:
                if target["port"] not in match_values(matches.get((protocol, "dport"))):
                    continue
                addresses = match_values(matches.get((family, "daddr")))
                coverage[operator].update((p, protocol) for p in target["addresses"] if p in addresses)
    return [operator for operator, covered in coverage.items()
            if covered and all((address, protocol) in covered
                               for address in target["addresses"]
                               for protocol in target["protocols"])]


def observe(targets):
    nft = shutil.which("nft")
    if not nft:
        raise FileNotFoundError("operator-firewall-tools-missing")
    tables = json.loads(run([nft, "-j", "list", "tables"]).stdout)["nftables"]
    manual = (json.loads(run([nft, "-j", "list", "chain", "inet", "mci_block", "input"]).stdout)["nftables"]
              if table_exists(tables, "mci_block") else [])
    managed = (json.loads(run([nft, "-j", "list", "table", "inet", TABLE]).stdout)["nftables"]
               if table_exists(tables, TABLE) else [])
    results = {}
    for node, target in targets.items():
        protocols = target["protocols"]
        matches = [] if target["mode"] == "gre" else manual_protocols(manual, target["port"], protocols)
        state = ("clear" if not matches else
                 "blocked" if set(matches) == set(protocols) else "partial")
        blocked = set(managed_blocks(managed, node, target))
        if state == "blocked":
            blocked.add("mci")
        results[node] = {"manual": state, "blocked": sorted(blocked)}
    return results


def legacy_nodes(nft, rules):
    if run([nft, "list", "table", "inet", "mci_block"], check=False).returncode:
        return []
    path = Path("/etc/mci-block.conf")
    config = path.read_text() if path.is_file() else ""
    ports = re.search(r'^PORTS="([0-9,]+)"$', config, re.M)
    proto = re.search(r'^PROTO="(tcp|udp|both)"$', config, re.M)
    return [int(node) for node, rule in rules.items()
            if not ports or not proto or
            (rule["port"] in set(map(int, ports[1].split(",")))
             and (proto[1] == "both" or proto[1] in rule["protocols"]))]


def apply(payload):
    rules = payload["rules"]
    if not rules and not PROFILE.exists() and not UNIT.exists():
        return []
    nft = shutil.which("nft")
    if not nft:
        raise FileNotFoundError("operator-firewall-tools-missing")
    if not Path("/run/systemd/system").is_dir():
        raise RuntimeError("systemd is required on the ingress server")
    ROOT.mkdir(mode=0o755, exist_ok=True)
    with (ROOT / ".lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        previous = PROFILE.read_text() if PROFILE.exists() else None
        old_unit = UNIT.read_text() if UNIT.exists() else None
        if any(data is not None and not data.startswith(OWNER) for data in (previous, old_unit)):
            raise RuntimeError("Firewall persistence files are not owned by SpeedBox")
        live = run([nft, "-j", "list", "table", "inet", TABLE], check=False)
        if live.returncode == 0:
            objects = json.loads(live.stdout)["nftables"]
            if not any(obj.get("table", {}).get("comment") == COMMENT for obj in objects):
                raise RuntimeError("Firewall table is not owned by SpeedBox")
        else:
            objects = []

        manual = legacy_nodes(nft, payload.get("inspect", rules))
        if rules:
            addresses = json.loads(run(["ip", "-j", "address", "show"]).stdout)
            local_ips = {ipaddress.ip_address(addr["local"]) for iface in addresses for addr in iface.get("addr_info", [])}
            sockets = run(["ss", "-H", "-lntup"]).stdout.splitlines()
            candidate = render(payload, local_ips, sockets)
        else:
            candidate = OWNER + RESET
        unit_text = OWNER + f"""[Unit]
Description=Restore SpeedBox operator access rules
After=nftables.service firewalld.service ufw.service
Before=network-online.target

[Service]
Type=oneshot
ExecStart={nft} -f {PROFILE}
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
"""
        if (rules and previous == candidate and old_unit == unit_text
                and any(obj.get("chain", {}).get("name") == "incoming" for obj in objects)):
            return manual
        run([nft, "-c", "-f", "-"], source=candidate)
        try:
            if old_unit != unit_text:
                write(UNIT, unit_text)
            if previous != candidate:
                write(PROFILE, candidate)
            if old_unit != unit_text:
                run(["systemctl", "daemon-reload"])
                run(["systemctl", "enable", UNIT_NAME])
            run([nft, "-f", "-"], source=candidate)
        except Exception:
            if previous is None:
                PROFILE.unlink(missing_ok=True)
            else:
                write(PROFILE, previous)
            if old_unit is None:
                run(["systemctl", "disable", UNIT_NAME], check=False)
                UNIT.unlink(missing_ok=True)
            else:
                write(UNIT, old_unit)
            if old_unit != unit_text:
                run(["systemctl", "daemon-reload"], check=False)
            raise
        return manual


if __name__ == "__main__":
    try:
        request = json.load(sys.stdin)
        if request.get("action") == "inspect":
            result = {"protocols": inspect_manual(request["port"], request["protocols"])}
        elif request.get("action") == "observe":
            result = {"nodes": observe(request["entries"])}
        else:
            result = {"manual": apply(request)}
        print(json.dumps({"ok": True, **result}))
    except FileNotFoundError as error:
        if str(error) != "operator-firewall-tools-missing":
            sys.exit(str(error))
        print(json.dumps({"ok": False, "error": "missing_tools"}))
    except Exception as error:
        sys.exit(str(error))
