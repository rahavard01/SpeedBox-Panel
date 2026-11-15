"""Small, checked integration overlay over an immutable upstream snapshot."""
from pathlib import Path
import shutil
import json

HERE = Path(__file__).resolve().parent


def replace(path, before, after, count=1):
    text = path.read_text()
    if text.count(before) != count:
        raise RuntimeError(f'Pinned source changed: {path.name}: {before[:70]!r}')
    path.write_text(text.replace(before, after))


def apply(source):
    source = Path(source)
    app = source / 'internal/app/app.go'
    for before, after in [('"/root/BackPack"', '"/opt/speedbox-backpack"'),
                          ('"/etc/backpack"', '"/etc/speedbox-backpack"'),
                          ('"backpack-"', '"speedbox-backpack-"'),
                          ('"/usr/local/bin/backpack"', '"/opt/speedbox-backpack/backpack"')]:
        replace(app, before, after)
    replace(source / 'internal/optimize/optimize.go', 'Apply(func(string) {}, reserve)',
            '// SpeedBox applies host-wide tuning only through its explicit maintenance action.')
    p = source / 'cmd/optimization.go'
    replace(p, 'optimize.EngineStartupTuning()', '[][2]string{}')
    replace(p, '"github.com/backpack/backpack/internal/optimize"',
            '_ "github.com/backpack/backpack/internal/optimize"')

    # Keep upstream defaults for ordinary tunnels. Only the isolated SpeedBox
    # interface namespace may use a removed carrier for existing configurations
    # and the private swapped-direction adapter. No other config gains it.
    l3 = source / 'internal/tunnel/l3'
    for path in (HERE / 'compat/l3').glob('*.go'):
        shutil.copy2(path, l3 / ('speedbox_compat_' + path.name))
    replace(l3 / 'config.go', 'c.Carrier = strings.ToLower(strings.TrimSpace(c.Carrier))',
            'c.Carrier = strings.ToLower(strings.TrimSpace(c.Carrier))\n\tif c.Carrier == \"\" && speedboxIface.MatchString(c.Iface) { c.Carrier = CarrierUDP }')
    replace(l3 / 'config.go', 'SockBuf int', 'SockBuf int\n\tMultipath MultipathConfig')
    replace(l3 / 'config.go', 'if !knownCarrier(c.Carrier) {',
            'if !knownCarrier(c.Carrier) && !speedboxLegacyConfig(*c) {')
    replace(l3 / 'config.go', 'if c.Carrier == CarrierPck {',
            'if c.Carrier == CarrierPck && !speedboxLegacyConfig(*c) {')
    replace(l3 / 'config.go', 'SockBuf:        table.SockBuf,',
            'SockBuf:        table.SockBuf,\n\t\tMultipath: MultipathConfig{Paths: table.SpeedboxLegacyPaths},')
    replace(source / 'config/l3.go', 'type L3Config struct {',
            'type L3Config struct {\n\tSpeedboxLegacyPaths int `toml:"paths"`')
    replace(l3 / 'carrier.go', 'name := strings.ToLower(strings.TrimSpace(cfg.Carrier))',
            'if speedboxLegacyConfig(cfg) { return speedboxOpenLegacy(ctx, cfg) }\n\tname := strings.ToLower(strings.TrimSpace(cfg.Carrier))')
    replace(l3 / 'tunnel.go', 'if cfg.Carrier == CarrierPck {',
            'if cfg.Carrier == CarrierPck && !speedboxLegacyConfig(cfg) {')
    replace(l3 / 'tunnel_handshake.go', 'case CarrierPck, CarrierSNI, CarrierXdi:',
            'case CarrierPck, CarrierSNI, CarrierXdi, CarrierUDP, CarrierQuic:')
    replace(source / 'internal/manage/tunnelspec/directrender.go',
            'if s.Carrier == l3.CarrierPck {',
            'if s.Carrier == l3.CarrierPck && !strings.HasPrefix(s.Iface, "sbp") {', count=2)

    # Existing key-confirmation and private-carrier keepalive remain necessary.
    from l3_confirm import apply as confirm
    confirm(source)
    for name in ['l3_confirm_test.go', 'l3_keepalive.go', 'l3_keepalive_test.go',
                 'legacy.go', 'legacy_test.go']:
        shutil.copy2(HERE / name, l3 / ('speedbox_' + name))

    ct = source / 'internal/manage/conntest.go'
    replace(ct, 'connTestConnectWait = 60 * time.Second', 'connTestConnectWait = 30 * time.Second')
    replace(ct, 'return connTestConnectWait + 30*time.Second', 'return connTestConnectWait + 15*time.Second')
    replace(ct, 'Iface: fmt.Sprintf("bpt%d", i)', 'Iface: speedboxTestIface(id, i)')
    replace(ct, 'spec.Name, spec.Iface = name, fmt.Sprintf("bpt%d", i)',
            'spec.Name, spec.Iface = name, speedboxTestIface(link.ID, i)')
    replace(ct, 'block := ctFreeL3Block(i)',
            'block := ctFreeL3Block(i)\n\t\t\tif l3BlockOwner(block+"1") != "" { c.skip="private network already in use"; s.cases=append(s.cases,c); continue }')
    # Do not shorten the new sustained speed measurement or discard its fields.
    p = source / 'internal/manage/directwebapi.go'
    text = p.read_text(); start = text.index('func l3BlockOwner(addr string) string {')
    p.write_text(text[:start] + text[start:].replace('return ""', 'return speedboxKernelBlockOwner(addr)', 1))
    for src, dst in [('speedbox.go', 'speedbox.go'),
                     ('managed.go', 'internal/manage/speedbox_managed.go'),
                     ('managed_test.go', 'internal/manage/speedbox_managed_test.go'),
                     ('bridge.go', 'internal/manage/speedbox_bridge.go'),
                     ('bridge_test.go', 'internal/manage/speedbox_bridge_test.go')]:
        shutil.copy2(HERE / src, source / dst)
    replace(source / 'internal/manage/setup.go', '"/usr/local/lib/backpack/helpers"',
            '"/opt/speedbox-backpack/helpers"')

    lock=json.loads((HERE/'upstream.json').read_text())
    helpers=json.loads((HERE/'helpers.json').read_text())
    generated='package manage\nconst speedboxUpstreamCommit='+json.dumps(lock['commit'])+'\n'
    generated+='var speedboxHelperVersions=map[string]string{'+','.join(json.dumps(k)+':'+json.dumps(v['version']) for k,v in sorted(helpers.items()))+'}\n'
    (source/'internal/manage/speedbox_version.go').write_text(generated)
