#!/usr/bin/env bash
# Non-interactive host preparation. Never write CLI output or licenses to logs.
set -Eeuo pipefail
umask 077
export LC_ALL=C LANG=C DEBIAN_FRONTEND=noninteractive
action="${1:-test}"
account="${2:-keep}"
secret_file="${3:-}"
installed=false
command -v warp-cli >/dev/null 2>&1 && installed=true
fail() {
    printf '{"installed":%s,"ready":false,"account":"unknown","error":"%s"}\n' "$installed" "$1"
    exit 0
}
trap 'fail command_failed' ERR
[[ "$action" == prepare || "$action" == test ]] || fail invalid_action
[[ "$account" == free || "$account" == plus || "$account" == keep ]] || fail invalid_account
[[ "$(id -u)" == 0 ]] || fail root_required
command -v flock >/dev/null 2>&1 || fail flock_missing
exec 9>/run/lock/speedbox-warp.lock
flock -n 9 || fail busy
cli() { timeout 40 warp-cli --accept-tos "$@" 2>/dev/null; }
proxy_mode() { grep -Eiq 'Mode:.*(WarpProxy|Proxy)|mode.*proxy' <<< "$1"; }
registration=''
if [[ "$installed" == true ]]; then
    registration="$(cli registration show || true)"
    settings="$(cli settings || true)"
    # Never turn an existing whole-host/Zero Trust tunnel into a proxy implicitly.
    if [[ "$action" == prepare && -n "$registration" ]] && ! proxy_mode "$settings"; then
        fail existing_mode
    fi
fi
if [[ "$action" == prepare ]]; then
    if [[ "$installed" == false ]]; then
        [[ -r /etc/os-release ]] || fail unsupported_os
        . /etc/os-release
        case "${ID:-}" in
            ubuntu|debian)
                case "${VERSION_CODENAME:-}" in jammy|noble|resolute|bookworm|trixie) ;; *) fail unsupported_os ;; esac
                apt-get update -qq >/dev/null 2>&1
                apt-get install -y -qq ca-certificates curl gnupg iproute2 >/dev/null 2>&1
                key_tmp="$(mktemp)"
                curl --fail --silent --show-error --connect-timeout 15 --max-time 90 https://pkg.cloudflareclient.com/pubkey.gpg -o "$key_tmp" 2>/dev/null
                gpg --batch --yes --dearmor --output /usr/share/keyrings/cloudflare-warp-archive-keyring.gpg "$key_tmp" 2>/dev/null
                rm -f -- "$key_tmp"
                chmod 644 /usr/share/keyrings/cloudflare-warp-archive-keyring.gpg
                printf 'deb [signed-by=/usr/share/keyrings/cloudflare-warp-archive-keyring.gpg] https://pkg.cloudflareclient.com/ %s main\n' "$VERSION_CODENAME" > /etc/apt/sources.list.d/cloudflare-client.list
                chmod 644 /etc/apt/sources.list.d/cloudflare-client.list
                apt-get update -qq >/dev/null 2>&1
                apt-get install -y -qq cloudflare-warp >/dev/null 2>&1
                ;;
            rhel|centos|rocky|almalinux|fedora)
                manager=dnf; command -v dnf >/dev/null || manager=yum
                "$manager" install -y curl ca-certificates iproute >/dev/null 2>&1
                curl --fail --silent --show-error --connect-timeout 15 --max-time 90 https://pkg.cloudflareclient.com/cloudflare-warp-ascii.repo -o /etc/yum.repos.d/cloudflare-warp.repo 2>/dev/null
                chmod 644 /etc/yum.repos.d/cloudflare-warp.repo
                "$manager" install -y cloudflare-warp >/dev/null 2>&1
                ;;
            *) fail unsupported_os ;;
        esac
        installed=true
    fi
    command -v curl >/dev/null 2>&1 || fail curl_missing
    command -v ss >/dev/null 2>&1 || fail ss_missing
    listeners="$(ss -H -lntp 'sport = :40000' 2>/dev/null)"
    if [[ -n "$listeners" ]] && grep -v 'warp-svc' <<< "$listeners" >/dev/null; then fail port_in_use; fi
    systemctl enable --now warp-svc >/dev/null 2>&1
    # A newly started service may need a moment before its IPC socket is ready.
    for attempt in 1 2 3 4 5; do cli status >/dev/null && break || sleep 1; done
    if ! cli registration show >/dev/null; then cli registration new >/dev/null || fail registration_failed; fi
    registration="$(cli registration show)"
    grep -Eiq 'Account type:.*Team' <<< "$registration" && fail existing_team
    if [[ "$account" == free ]] && grep -Eiq 'Account type:.*(Unlimited|Limited|Plus)' <<< "$registration"; then
        # Account changes affect every WARP node on this host and are explicit in the UI.
        cli disconnect >/dev/null || true
        cli registration delete >/dev/null || fail registration_failed
        cli registration new >/dev/null || fail registration_failed
    fi
    cli tunnel protocol set MASQUE >/dev/null || fail masque_failed
    cli mode proxy >/dev/null || fail proxy_failed
    cli proxy port 40000 >/dev/null || fail proxy_failed
    if [[ "$account" == plus ]]; then
        if [[ -n "$secret_file" && -s "$secret_file" ]]; then
            license="$(cat -- "$secret_file")"
            [[ "$license" =~ ^[A-Za-z0-9-]{1,128}$ ]] || fail license_invalid
            cli registration license "$license" >/dev/null || fail license_invalid
            unset license
        fi
        registration="$(cli registration show)"
        grep -Eiq 'Account type:.*(Unlimited|Limited|Plus)' <<< "$registration" || fail license_invalid
    fi
    cli connect >/dev/null || fail connect_failed
fi
[[ "$installed" == true ]] || fail not_installed
command -v curl >/dev/null 2>&1 || fail curl_missing
command -v ss >/dev/null 2>&1 || fail ss_missing
settings="$(cli settings || true)"
proxy_mode "$settings" || fail existing_mode
registration="$(cli registration show || true)"
actual_account=unknown
grep -Eiq 'Account type:.*Free' <<< "$registration" && actual_account=free
grep -Eiq 'Account type:.*(Unlimited|Limited|Plus)' <<< "$registration" && actual_account=plus
trace=''
for attempt in 1 2 3; do
    # Reject non-loopback listeners; this integration never exposes a public proxy.
    listeners="$(ss -H -lntp 'sport = :40000' 2>/dev/null)"
    if [[ -n "$listeners" ]]; then
        grep -v 'warp-svc' <<< "$listeners" >/dev/null && fail port_in_use
        awk '{print $4}' <<< "$listeners" | grep -Ev '^(127\.0\.0\.1|\[::1\]|::1):40000$' >/dev/null && fail public_listener
        trace="$(curl --fail --silent --show-error --noproxy '' --proxy socks5h://127.0.0.1:40000 --connect-timeout 5 --max-time 12 https://www.cloudflare.com/cdn-cgi/trace 2>/dev/null || true)"
        grep -Eq '^warp=(on|plus)$' <<< "$trace" && break
    fi
    [[ "$action" == prepare ]] || break
    sleep 2
done
grep -Eq '^warp=(on|plus)$' <<< "$trace" || fail connection_failed
ip="$(sed -n 's/^ip=//p' <<< "$trace" | head -n1 | tr -d '\r')"
[[ "$ip" =~ ^[0-9a-fA-F.:]+$ ]] || ip=''
printf '{"installed":true,"ready":true,"account":"%s","ip":"%s"}\n' "$actual_account" "$ip"
