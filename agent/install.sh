#!/usr/bin/env bash
set -Eeuo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

APP_NAME="xboard-node"
INSTALL_ROOT="/etc/xboard-node"
BACKUP_DIR="${INSTALL_ROOT}/backups"
INSTALL_META="${INSTALL_ROOT}/install-meta.json"
CONFIG_FILE="${INSTALL_ROOT}/config.yml"
CREDENTIALS_FILE="${INSTALL_ROOT}/credentials.env"
BINARY_PATH="/usr/local/bin/xboard-node"
SERVICE_NAME="xboard-node.service"
SERVICE_PATH="/etc/systemd/system/${SERVICE_NAME}"
CLI_PATH="/usr/local/bin/xbctl"
INSTALLER_COPY_PATH="${INSTALL_ROOT}/install.sh"
CLI_BINARY_SOURCE=""
DEFAULT_HEALTH_PORT=65530
DEFAULT_KERNEL="singbox"
DEFAULT_MODE="node"
DEFAULT_ACTION="install"
DEFAULT_RELEASE_VERSION="latest"
DEFAULT_LOG_LEVEL="info"
DEFAULT_KERNEL_LOG_LEVEL="warn"
DEFAULT_DOWNLOAD_BASE="https://github.com/cedar2025/xboard-node/releases"

ACTION="${DEFAULT_ACTION}"
MODE=""
PANEL_URL=""
TOKEN=""
NODE_ID=""
NODE_TYPE=""
MACHINE_ID=""
KERNEL_TYPE="${DEFAULT_KERNEL}"
RELEASE_VERSION="${DEFAULT_RELEASE_VERSION}"
HEALTH_PORT="${DEFAULT_HEALTH_PORT}"
HEALTH_ENABLED=1
RUNTIME_GOMEMLIMIT=""
RUNTIME_GOGC=""
BINARY_SOURCE=""
CLI_BINARY_SOURCE=""
FORCE_RECONFIGURE=0
PURGE=0
YES=0
ARCH=""
OS=""
DOWNLOAD_URL=""
CURRENT_STATE="fresh"
TMP_DIR=""
BACKUP_PATH=""
SERVICE_EXISTED=0
SERVICE_ENABLED=0
CLEANUP_DONE=0
EXPECTED_VERSION=""
COMMITTED=0
REPLACEMENT_STARTED=0

log_info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_step()  { echo -e "${CYAN}[STEP]${NC} ${BOLD}$1${NC}"; }

cleanup_tmp() {
    if [ "$CLEANUP_DONE" -eq 1 ]; then
        return
    fi
    CLEANUP_DONE=1
    if [ -n "$TMP_DIR" ] && [ -d "$TMP_DIR" ]; then
        rm -rf "$TMP_DIR"
    fi
}

load_health_port_from_config() {
    local cfg_path="$1"
    if [ ! -f "$cfg_path" ]; then
        return
    fi
    local parsed
    if [ -x "$CLI_PATH" ]; then
        parsed=$("$CLI_PATH" config health-port --config "$cfg_path" 2>/dev/null)
    else
        parsed=$(grep -m1 'health_port:' "$cfg_path" 2>/dev/null | sed 's/.*health_port:[[:space:]]*//' | tr -cd '0-9')
    fi
    if [ -n "$parsed" ] && [ "$parsed" -ge 0 ] 2>/dev/null; then
        HEALTH_PORT="$parsed"
        if [ "$HEALTH_PORT" -eq 0 ]; then
            HEALTH_ENABLED=0
        else
            HEALTH_ENABLED=1
        fi
    fi
}

rollback_install() {
    log_warn "Rolling back installation"
    systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
    if [ -n "$BACKUP_PATH" ] && [ -d "$BACKUP_PATH" ]; then
        if [ -f "$BACKUP_PATH/xboard-node" ]; then
            install_binary_atomic "$BACKUP_PATH/xboard-node" "$BINARY_PATH" || return 1
        else
            rm -f "$BINARY_PATH"
        fi
        if [ -f "$BACKUP_PATH/config.yml" ]; then
            install -m 600 "$BACKUP_PATH/config.yml" "$CONFIG_FILE" || return 1
        else
            rm -f "$CONFIG_FILE"
        fi
        if [ -f "$BACKUP_PATH/credentials.env" ]; then
            install -m 600 "$BACKUP_PATH/credentials.env" "$CREDENTIALS_FILE" || return 1
        else
            rm -f "$CREDENTIALS_FILE"
        fi
        if [ -f "$BACKUP_PATH/install-meta.json" ]; then
            install -m 644 "$BACKUP_PATH/install-meta.json" "$INSTALL_META" || return 1
        else
            rm -f "$INSTALL_META"
        fi
        if [ -f "$BACKUP_PATH/xbctl" ]; then
            install_binary_atomic "$BACKUP_PATH/xbctl" "$CLI_PATH" || return 1
        else
            rm -f "$CLI_PATH"
            if [ "$(readlink /usr/bin/xbctl 2>/dev/null || true)" = "$CLI_PATH" ]; then rm -f /usr/bin/xbctl; fi
        fi
        if [ -f "$BACKUP_PATH/${SERVICE_NAME}" ]; then
            install -m 644 "$BACKUP_PATH/${SERVICE_NAME}" "$SERVICE_PATH" || return 1
        else
            rm -f "$SERVICE_PATH"
        fi
    fi
    load_health_port_from_config "$CONFIG_FILE"
    systemctl daemon-reload || true
    if [ "$SERVICE_ENABLED" -eq 1 ]; then
        systemctl enable "$SERVICE_NAME" >/dev/null 2>&1 || true
    else
        systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true
        if [ "$SERVICE_EXISTED" -eq 0 ]; then
            rm -f "/etc/systemd/system/multi-user.target.wants/$SERVICE_NAME"
        fi
    fi
    if [ "$SERVICE_EXISTED" -eq 1 ] || [ -f "$SERVICE_PATH" ]; then
        systemctl reset-failed "$SERVICE_NAME" >/dev/null 2>&1 || true
        systemctl restart "$SERVICE_NAME" >/dev/null 2>&1 || true
        if ! wait_for_health; then
            log_error "Rollback completed but restored service did not become healthy"
            show_recent_logs
            return 1
        fi
    else
        systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true
    fi
    log_warn "Rollback complete"
}

on_error() {
    local exit_code=${2:-$?}
    local line_no=${1:-unknown}
    if [ "$exit_code" -ne 0 ]; then
        log_error "Install failed at line ${line_no} (exit=${exit_code})"
        if [ -n "$BACKUP_PATH" ] && [ "$REPLACEMENT_STARTED" -eq 1 ] && [ "$COMMITTED" -eq 0 ]; then
            rollback_install || true
        fi
    fi
    cleanup_tmp
    exit "$exit_code"
}
trap 'on_error $LINENO' ERR
trap 'on_error $LINENO 130' INT
trap 'on_error $LINENO 143' TERM
trap 'on_error $LINENO 129' HUP
trap cleanup_tmp EXIT

usage() {
    cat <<'HELP'

  xboard-node Installer

  ACTIONS:
    install      Install or reconcile the configured deployment (default)
    upgrade      Upgrade binary and restart service
    uninstall    Remove installed service and binary (config kept unless --purge)
    status       Show current installation status
    help         Show this help

  MODES (auto-detected from --node-id or --machine-id if omitted):
    --mode node      Panel single-node mode (default)
    --mode machine   Panel machine mode

  REQUIRED FOR NODE MODE:
    --panel, -a      Panel URL
    --token, -t      Panel server token
    --node-id, -n    Node ID

  REQUIRED FOR MACHINE MODE:
    --panel, -a       Panel URL
    --token, -t       Machine token
    --machine-id      Machine ID

  OPTIONAL:
    --node-type, -T     Explicit node type for node mode
    --kernel, -k        singbox or xray (default: singbox)
    --version           Release version or latest (default: latest)
    --binary            Use a local xboard-node binary path instead of downloading
    --xbctl-binary      Use a local xbctl binary path instead of downloading
    --expected-version Require this version marker in the supplied binary
    --health-port       Local health port (default: 65530, use 0 to disable)
    --gomemlimit        Runtime GOMEMLIMIT value, e.g. 256MiB
    --gogc              Runtime GOGC value, e.g. 50
    --force-reconfigure Overwrite an existing install even if mode/target changed
    --purge             With uninstall, delete /etc/xboard-node too
    --yes, -y           Non-interactive confirmation for destructive operations

  EXAMPLES:
    sudo bash install.sh --panel https://panel.example.com --token TOKEN --node-id 1
    sudo bash install.sh --panel https://panel.example.com --token TOKEN --machine-id 1
    sudo bash install.sh upgrade
    sudo bash install.sh uninstall --purge --yes

HELP
}

parse_args() {
    local positional=()
    while [ $# -gt 0 ]; do
        case "$1" in
            install|upgrade|uninstall|status|help)
                ACTION="$1"
                shift
                ;;
            --mode)
                MODE="$2"
                shift 2
                ;;
            --panel|-a|--api)
                PANEL_URL="$2"
                shift 2
                ;;
            --token|-t)
                TOKEN="$2"
                shift 2
                ;;
            --node-id|-n)
                NODE_ID="$2"
                shift 2
                ;;
            --node-type|-T)
                NODE_TYPE="$2"
                shift 2
                ;;
            --machine-id)
                MACHINE_ID="$2"
                shift 2
                ;;
            --kernel|-k)
                KERNEL_TYPE="$2"
                shift 2
                ;;
            --version)
                RELEASE_VERSION="$2"
                shift 2
                ;;
            --binary)
                BINARY_SOURCE="$2"
                shift 2
                ;;
            --xbctl-binary)
                CLI_BINARY_SOURCE="$2"
                shift 2
                ;;
            --expected-version)
                EXPECTED_VERSION="$2"
                shift 2
                ;;
            --health-port)
                HEALTH_PORT="$2"
                shift 2
                ;;
            --gomemlimit)
                RUNTIME_GOMEMLIMIT="$2"
                shift 2
                ;;
            --gogc)
                RUNTIME_GOGC="$2"
                shift 2
                ;;
            --force-reconfigure)
                FORCE_RECONFIGURE=1
                shift
                ;;
            --purge)
                PURGE=1
                shift
                ;;
            --yes|-y)
                YES=1
                shift
                ;;
            --help|-h)
                ACTION="help"
                shift
                ;;
            *)
                positional+=("$1")
                shift
                ;;
        esac
    done

    if [ ${#positional[@]} -gt 0 ] && [ "$ACTION" = "install" ]; then
        ACTION="${positional[0]}"
    fi

    case "$KERNEL_TYPE" in
        singbox|SingBox|SINGBOX) KERNEL_TYPE="singbox" ;;
        xray|Xray|XRAY) KERNEL_TYPE="xray" ;;
        *) ;;
    esac

    # Auto-detect mode from arguments when --mode is not specified.
    if [ -z "$MODE" ]; then
        if [ -n "$MACHINE_ID" ]; then
            MODE="machine"
        else
            MODE="node"
        fi
    fi

    case "$MODE" in
        node|machine) ;;
        *)
            log_error "Unsupported mode: $MODE"
            usage
            exit 1
            ;;
    esac
}

check_root() {
    if [ "$(id -u)" -ne 0 ]; then
        log_error "Please run as root or with sudo"
        exit 1
    fi
}

detect_arch() {
    local raw
    raw=$(uname -m)
    case "$raw" in
        x86_64|amd64) ARCH="amd64" ;;
        aarch64|arm64) ARCH="arm64" ;;
        *)
            log_error "Unsupported architecture: $raw"
            exit 1
            ;;
    esac
}

detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS="$ID"
    else
        OS="unknown"
    fi
}

ensure_systemd() {
    if ! command -v systemctl >/dev/null 2>&1; then
        log_error "systemd is required for this installer"
        exit 1
    fi
    if [ ! -d /run/systemd/system ]; then
        log_error "This host does not appear to be running systemd"
        exit 1
    fi
}

run_with_retry() {
    local attempts="$1"
    local delay="$2"
    shift 2
    local i=1
    while [ "$i" -le "$attempts" ]; do
        if "$@"; then
            return 0
        fi
        if [ "$i" -lt "$attempts" ]; then
            log_warn "Command failed, retrying in ${delay}s: $*"
            sleep "$delay"
        fi
        i=$((i + 1))
    done
    return 1
}

install_dependencies() {
    # Normal panel deployments upload ready binaries. Do not make a healthy
    # server depend on an unnecessary apt update or an unreachable mirror.
    if command -v curl >/dev/null 2>&1 && command -v flock >/dev/null 2>&1 && command -v sha256sum >/dev/null 2>&1 &&
       { [ -s /etc/ssl/certs/ca-certificates.crt ] || [ -s /etc/pki/tls/certs/ca-bundle.crt ]; }; then
        return
    fi
    case "$OS" in
        ubuntu|debian)
            DEBIAN_FRONTEND=noninteractive run_with_retry 3 3 timeout 120 apt-get update -qq
            DEBIAN_FRONTEND=noninteractive run_with_retry 3 3 timeout 120 apt-get install -y -qq curl ca-certificates coreutils util-linux
            ;;
        centos|rhel|rocky|almalinux|fedora)
            if command -v dnf >/dev/null 2>&1; then
                run_with_retry 3 3 timeout 120 dnf install -y -q curl ca-certificates coreutils util-linux
            else
                run_with_retry 3 3 timeout 120 yum install -y -q curl ca-certificates coreutils util-linux
            fi
            ;;
        *)
            log_error "Required runtime tools are missing on unsupported OS ${OS}"
            return 1
            ;;
    esac
}

ensure_dirs() {
    mkdir -p "$INSTALL_ROOT" "$BACKUP_DIR"
    chmod 700 "$INSTALL_ROOT"
}

verify_disk_space() {
    local bytes=0 source needed available target
    for source in "$BINARY_SOURCE" "$CLI_BINARY_SOURCE"; do
        if [ -n "$source" ] && [ -f "$source" ]; then
            bytes=$((bytes + $(stat -c %s "$source")))
        fi
    done
    needed=$((bytes * 3 / 1024 + 32768))
    if [ "$bytes" -eq 0 ]; then needed=524288; fi
    for target in /usr/local/bin /etc; do
        available=$(df -Pk "$target" | awk 'NR==2 {print $4}')
        if [ -z "$available" ] || [ "$available" -lt "$needed" ]; then
            log_error "Not enough free disk space for staging and rollback on $target"
            return 1
        fi
    done
}

verify_panel_access() {
    [ "$MODE" = "machine" ] || return 0
    local token_file="$TMP_DIR/panel-token"
    printf '%s' "$TOKEN" > "$token_file"
    chmod 600 "$token_file"
    log_step "Checking machine authentication with the panel"
    local args=(--fail --silent --show-error --connect-timeout 10 --max-time 30
        --retry 2 --retry-delay 2 --header 'Accept: application/json'
        --data-urlencode "token@$token_file" --data-urlencode "machine_id=$MACHINE_ID")
    if ! curl "${args[@]}" "${PANEL_URL%/}/api/v2/server/machine/nodes" -o /dev/null &&
       ! curl -4 "${args[@]}" "${PANEL_URL%/}/api/v2/server/machine/nodes" -o /dev/null; then
        log_error "Machine cannot authenticate with the panel; existing installation was not replaced"
        return 1
    fi
    rm -f "$token_file"
}

install_binary_atomic() {
    local source="$1" destination="$2" staged
    staged=$(mktemp "${destination}.XXXXXX")
    install -m 755 "$source" "$staged" || { rm -f "$staged"; return 1; }
    mv -f "$staged" "$destination"
}

validate_positive_int() {
    local label="$1"
    local value="$2"
    if ! [[ "$value" =~ ^[0-9]+$ ]] || [ "$value" -le 0 ]; then
        log_error "${label} must be a positive integer, got: ${value}"
        exit 1
    fi
}

validate_install_request() {
    if [ -z "$PANEL_URL" ]; then
        log_error "Panel URL is required"
        exit 1
    fi
    if [ -z "$TOKEN" ]; then
        log_error "Token is required"
        exit 1
    fi
    if ! [[ "$PANEL_URL" =~ ^https?:// ]]; then
        log_error "Panel URL must use http or https"
        return 1
    fi
    if ! [[ "$HEALTH_PORT" =~ ^[0-9]+$ ]]; then
        log_error "health-port must be a non-negative integer"
        exit 1
    fi
    if [ "$HEALTH_PORT" -eq 0 ]; then
        HEALTH_ENABLED=0
    fi
    case "$KERNEL_TYPE" in
        singbox|xray) ;;
        *)
            log_error "Kernel must be singbox or xray"
            exit 1
            ;;
    esac
    case "$MODE" in
        node)
            validate_positive_int "Node ID" "$NODE_ID"
            ;;
        machine)
            validate_positive_int "Machine ID" "$MACHINE_ID"
            ;;
    esac
}

detect_current_state() {
    local has_binary=0 has_config=0 has_service=0
    [ -x "$BINARY_PATH" ] && has_binary=1
    [ -f "$CONFIG_FILE" ] && has_config=1
    [ -f "$SERVICE_PATH" ] && has_service=1

    if [ "$has_binary" -eq 0 ] && [ "$has_config" -eq 0 ] && [ "$has_service" -eq 0 ]; then
        CURRENT_STATE="fresh"
    elif [ "$has_binary" -eq 1 ] && [ "$has_config" -eq 1 ] && [ "$has_service" -eq 1 ]; then
        CURRENT_STATE="installed"
    else
        CURRENT_STATE="partial"
    fi
}

require_reconfigure_confirmation() {
    return
}

select_binary_source() {
    if [ -n "$BINARY_SOURCE" ]; then
        if [ ! -f "$BINARY_SOURCE" ]; then
            log_error "Binary source not found: $BINARY_SOURCE"
            exit 1
        fi
        echo "$BINARY_SOURCE"
        return
    fi
    if [ -f "./xboard-node" ]; then
        echo "./xboard-node"
        return
    fi
    if [ -f "./xboard-node-linux-${ARCH}" ]; then
        echo "./xboard-node-linux-${ARCH}"
        return
    fi
    echo ""
}

resolve_download_url() {
    local artifact="$1"
    if [ "$RELEASE_VERSION" = "latest" ]; then
        DOWNLOAD_URL="${DEFAULT_DOWNLOAD_BASE}/latest/download/${artifact}"
    else
        DOWNLOAD_URL="${DEFAULT_DOWNLOAD_BASE}/download/${RELEASE_VERSION}/${artifact}"
    fi
}

stage_binary() {
    local staged="$TMP_DIR/xboard-node"
    local local_src
    local_src=$(select_binary_source)
    if [ -n "$local_src" ]; then
        log_step "Using local binary: ${local_src}"
        cp "$local_src" "$staged"
    else
        resolve_download_url "xboard-node-linux-${ARCH}"
        log_step "Downloading binary: ${DOWNLOAD_URL}"
        if ! curl -fsSL "$DOWNLOAD_URL" -o "$staged"; then
            log_error "Failed to download binary from ${DOWNLOAD_URL}"
            exit 1
        fi
    fi
    chmod +x "$staged"
    local binary_version
    if ! binary_version=$("$staged" -v 2>&1); then
        log_error "Downloaded binary failed version check"
        exit 1
    fi
    if [ -n "$EXPECTED_VERSION" ] && [[ "$binary_version" != *"$EXPECTED_VERSION"* ]]; then
        log_error "Staged binary is outdated; rebuild the panel's HTTP-obfs agent package"
        return 1
    fi
}

stage_xbctl() {
    local staged="$TMP_DIR/xbctl"
    local local_src=""
    if [ -n "$CLI_BINARY_SOURCE" ]; then
        if [ ! -f "$CLI_BINARY_SOURCE" ]; then
            log_error "xbctl binary source not found: $CLI_BINARY_SOURCE"
            exit 1
        fi
        local_src="$CLI_BINARY_SOURCE"
    elif [ -f "./xbctl" ]; then
        local_src="./xbctl"
    elif [ -f "./xbctl-linux-${ARCH}" ]; then
        local_src="./xbctl-linux-${ARCH}"
    fi
    if [ -n "$local_src" ]; then
        log_step "Using local xbctl binary: ${local_src}"
        cp "$local_src" "$staged"
    else
        resolve_download_url "xbctl-linux-${ARCH}"
        log_step "Downloading xbctl: ${DOWNLOAD_URL}"
        if ! curl -fsSL "$DOWNLOAD_URL" -o "$staged"; then
            log_error "Failed to download xbctl from ${DOWNLOAD_URL}"
            exit 1
        fi
    fi
    chmod +x "$staged"
    local cli_version
    if ! cli_version=$("$staged" version 2>&1); then
        log_error "Downloaded xbctl failed version check"
        exit 1
    fi
    if [ -n "$EXPECTED_VERSION" ] && [[ "$cli_version" != *"$EXPECTED_VERSION"* ]]; then
        log_error "Staged xbctl is outdated; rebuild the panel's HTTP-obfs agent package"
        return 1
    fi
}

render_config() {
    local init_args=(
        config init
        --mode "$MODE"
        --panel-url "$PANEL_URL"
        --kernel "${KERNEL_TYPE:-singbox}"
        --health-port "${HEALTH_PORT:-0}"
        --token "$TOKEN"
        --version "$RELEASE_VERSION"
        --output "$TMP_DIR/config.yml"
        --credentials-out "$TMP_DIR/credentials.env"
        --meta "$TMP_DIR/install-meta.json"
        --install-root "$INSTALL_ROOT"
    )
    if [ -f "$CONFIG_FILE" ]; then
        init_args+=(--config "$CONFIG_FILE")
    fi
    if [ -f "$CREDENTIALS_FILE" ]; then
        init_args+=(--credentials-in "$CREDENTIALS_FILE")
    fi
    if [ "$MODE" = "machine" ]; then
        init_args+=(--machine-id "$MACHINE_ID")
    else
        init_args+=(--node-id "$NODE_ID")
        if [ -n "$NODE_TYPE" ]; then
            init_args+=(--node-type "$NODE_TYPE")
        fi
    fi
    if [ -n "$RUNTIME_GOMEMLIMIT" ]; then
        init_args+=(--gomemlimit "$RUNTIME_GOMEMLIMIT")
    fi
    if [ -n "$RUNTIME_GOGC" ] && [ "$RUNTIME_GOGC" -gt 0 ] 2>/dev/null; then
        init_args+=(--gogc "$RUNTIME_GOGC")
    fi

    local output
    output=$("$TMP_DIR/xbctl" "${init_args[@]}") || {
        log_error "xbctl config init failed"
        exit 1
    }

    INSTANCE_ID=$(echo "$output" | grep '^INSTANCE_ID=' | cut -d= -f2-)
    chmod 600 "$TMP_DIR/credentials.env"
}

render_service() {
    cat >"$TMP_DIR/${SERVICE_NAME}" <<EOF_UNIT
[Unit]
Description=Xboard Node Backend
Documentation=https://github.com/cedar2025/xboard-node
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=${INSTALL_ROOT}
EnvironmentFile=-${CREDENTIALS_FILE}
ExecStart=${BINARY_PATH} -c ${CONFIG_FILE}
Restart=always
RestartSec=5
LimitNOFILE=1048576
NoNewPrivileges=true
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF_UNIT
}

backup_existing_state() {
    local candidate
    candidate=$(mktemp -d "${BACKUP_DIR}/$(date +%Y%m%d-%H%M%S)-XXXXXX")
    if systemctl is-enabled "$SERVICE_NAME" >/dev/null 2>&1; then SERVICE_ENABLED=1; else SERVICE_ENABLED=0; fi
    if [ -x "$BINARY_PATH" ]; then
        cp "$BINARY_PATH" "$candidate/xboard-node"
    fi
    if [ -x "$CLI_PATH" ]; then
        cp "$CLI_PATH" "$candidate/xbctl"
    fi
    if [ -f "$CONFIG_FILE" ]; then
        cp "$CONFIG_FILE" "$candidate/config.yml"
    fi
    if [ -f "$CREDENTIALS_FILE" ]; then
        cp "$CREDENTIALS_FILE" "$candidate/credentials.env"
    fi
    if [ -f "$INSTALL_META" ]; then
        cp "$INSTALL_META" "$candidate/install-meta.json"
    fi
    if [ -f "$SERVICE_PATH" ]; then
        cp "$SERVICE_PATH" "$candidate/${SERVICE_NAME}"
        SERVICE_EXISTED=1
    else
        SERVICE_EXISTED=0
    fi
    # Publish the rollback path only after every existing file is backed up.
    BACKUP_PATH="$candidate"
}

stop_existing_service() {
    if [ -f "$SERVICE_PATH" ] || systemctl is-active "$SERVICE_NAME" >/dev/null 2>&1; then
        systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
    fi
}

install_staged_files() {
    stop_existing_service
    install_binary_atomic "$TMP_DIR/xboard-node" "$BINARY_PATH"
    install -m 600 "$TMP_DIR/config.yml" "$CONFIG_FILE"
    install -m 600 "$TMP_DIR/credentials.env" "$CREDENTIALS_FILE"
    install -m 644 "$TMP_DIR/install-meta.json" "$INSTALL_META"
    if [ -f "$0" ] && [ "$(realpath "$0")" != "$(realpath "$INSTALLER_COPY_PATH" 2>/dev/null || echo "$INSTALLER_COPY_PATH")" ]; then
        install -m 755 "$0" "$INSTALLER_COPY_PATH"
    fi
    install_binary_atomic "$TMP_DIR/xbctl" "$CLI_PATH"
    ln -sf "$CLI_PATH" /usr/bin/xbctl 2>/dev/null || true
    install -m 644 "$TMP_DIR/${SERVICE_NAME}" "$SERVICE_PATH"
    systemctl daemon-reload
    systemctl enable "$SERVICE_NAME" > /dev/null 2>&1
}

wait_for_health() {
    if [ "$HEALTH_ENABLED" -eq 0 ]; then
        systemctl is-active "$SERVICE_NAME" >/dev/null 2>&1
        return $?
    fi
    local attempt=0
    local max_attempts=60
    while [ "$attempt" -lt "$max_attempts" ]; do
        if systemctl is-active "$SERVICE_NAME" >/dev/null 2>&1 && curl -fsS --connect-timeout 1 --max-time 2 "http://127.0.0.1:${HEALTH_PORT}/healthz" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
        attempt=$((attempt + 1))
    done
    return 1
}

show_recent_logs() {
    if command -v journalctl >/dev/null 2>&1; then
        journalctl -u "$SERVICE_NAME" -n 30 --no-pager || true
    fi
}

start_service() {
    if systemctl is-enabled "$SERVICE_NAME" >/dev/null 2>&1; then
        systemctl restart "$SERVICE_NAME"
    else
        systemctl start "$SERVICE_NAME"
    fi
    if ! wait_for_health; then
        log_error "Service failed health check"
        show_recent_logs
        return 1
    fi
}

perform_install() {
    validate_install_request
    detect_current_state
    require_reconfigure_confirmation
    ensure_dirs
    # Run staged binaries on their final executable filesystem, not /tmp
    # which is mounted noexec on some providers.
    TMP_DIR=$(mktemp -d /usr/local/bin/.speedbox-stage.XXXXXX)
    stage_binary
    stage_xbctl
    render_config
    render_service
    verify_panel_access
    backup_existing_state
    REPLACEMENT_STARTED=1
    install_staged_files
    start_service
    COMMITTED=1

    log_info "Installation succeeded"
    log_info "Service: ${SERVICE_NAME}"
    log_info "Config: ${CONFIG_FILE}"
    log_info "Credentials: ${CREDENTIALS_FILE}"
    if [ "$HEALTH_ENABLED" -eq 1 ]; then
        log_info "Health: http://127.0.0.1:${HEALTH_PORT}/healthz"
    fi
    log_info "CLI: ${CLI_PATH}  (run '${CLI_PATH} list' if xbctl is not in PATH)"
}

perform_upgrade() {
    detect_current_state
    if [ "$CURRENT_STATE" = "fresh" ]; then
        log_warn "No existing install found; falling back to install"
        perform_install
        return
    fi
    ensure_dirs
    TMP_DIR=$(mktemp -d /usr/local/bin/.speedbox-stage.XXXXXX)
    stage_binary
    stage_xbctl
    render_service
    backup_existing_state
    REPLACEMENT_STARTED=1
    install_binary_atomic "$TMP_DIR/xboard-node" "$BINARY_PATH"
    install_binary_atomic "$TMP_DIR/xbctl" "$CLI_PATH"
    ln -sf "$CLI_PATH" /usr/bin/xbctl 2>/dev/null || true
    install -m 644 "$TMP_DIR/${SERVICE_NAME}" "$SERVICE_PATH"
    systemctl daemon-reload
    systemctl restart "$SERVICE_NAME"
    if ! wait_for_health; then
        log_error "Upgrade health check failed"
        show_recent_logs
        return 1
    fi
    COMMITTED=1
    log_info "Upgrade succeeded"
}

confirm_uninstall() {
    if [ "$YES" -eq 1 ]; then
        return
    fi
    echo
    read -r -p "Proceed with uninstall? [y/N]: " answer
    if ! [[ "$answer" =~ ^[Yy]$ ]]; then
        log_warn "Uninstall cancelled"
        exit 0
    fi
}

perform_uninstall() {
    confirm_uninstall
    if [ -f "$SERVICE_PATH" ]; then
        systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
        systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true
        rm -f "$SERVICE_PATH"
        systemctl daemon-reload || true
    fi
    rm -f "$BINARY_PATH"
    rm -f "$CLI_PATH"
    rm -f /usr/bin/xbctl 2>/dev/null || true
    if [ "$PURGE" -eq 1 ]; then
        rm -rf "$INSTALL_ROOT"
        log_info "Removed ${INSTALL_ROOT}"
    else
        rm -f "$INSTALL_META"
        log_info "Config preserved under ${INSTALL_ROOT}"
    fi
    log_info "Uninstall complete"
}

perform_status() {
    detect_current_state
    echo
    echo -e "${BOLD}xboard-node install status${NC}"
    echo "  state:   ${CURRENT_STATE}"
    if [ -f "$INSTALL_META" ]; then
        echo "  meta:    ${INSTALL_META}"
        if [ -x "$CLI_PATH" ]; then
            "$CLI_PATH" list 2>/dev/null || true
        else
            # Simple key extraction from JSON (no Python needed)
            local val
            for key in config_mode version latest_instance_id instance_count updated_at; do
                val=$(sed -n "s/.*\"${key}\": *\"\{0,1\}\([^\"]*\)\"\{0,1\}.*/\1/p" "$INSTALL_META" | head -1)
                val="${val%,}"  # strip trailing comma from numeric JSON values
                [ -n "$val" ] && echo "  ${key}: ${val}"
            done
        fi
    fi
    if [ -f "$SERVICE_PATH" ]; then
        echo "  service: ${SERVICE_NAME}"
        systemctl status "$SERVICE_NAME" --no-pager || true
    fi
}

main() {
    parse_args "$@"
    case "$ACTION" in
        help)
            usage
            exit 0
            ;;
        status)
            ensure_systemd
            perform_status
            exit 0
            ;;
    esac

    check_root
    detect_arch
    detect_os
    ensure_systemd
    if [ "$ACTION" = "install" ] || [ "$ACTION" = "upgrade" ]; then install_dependencies; fi
    mkdir -p /run/lock
    exec 9>/run/lock/panel-speedbox-node.lock
    if ! flock -n 9; then log_error "Another node install or removal is running"; return 1; fi
    if [ "$ACTION" = "install" ] || [ "$ACTION" = "upgrade" ]; then
        verify_disk_space
    fi

    case "$ACTION" in
        install)
            perform_install
            ;;
        upgrade)
            perform_upgrade
            ;;
        uninstall)
            perform_uninstall
            ;;
        *)
            log_error "Unknown action: $ACTION"
            usage
            exit 1
            ;;
    esac
}

main "$@"

