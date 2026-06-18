# Send a POST request to the given URL, ignoring the response and errors
function httpPostSilently()
{
    local url="${1:-}"
    local data="${2:-}"

    if [ -z "$data" ]; then
        (curl -X POST --silent --max-time 15 --output /dev/null "$url" || true)
    else
        (curl -X POST --silent --max-time 15 --output /dev/null "$url" -H 'Content-Type: application/json' --data "$data" || true)
    fi
}

# Send a POST request and return success ONLY on a 2xx response.
#
# The previous version used `curl --output /dev/null` with no status check, so
# curl exited 0 on a 302/403/503 too — meaning the box reported "callback
# delivered" even when a middleware gate redirected the request to a login /
# coming-soon page and the task was never updated. That silent failure is what
# left provisioning wedged. Capture the HTTP status, treat only 2xx as success,
# and retry transient failures (network blips, brief gate windows) a few times.
# Deliberately do NOT follow redirects (-L): a redirect to a gate page IS the
# failure signal, not something to chase.
function httpPost()
{
    local url="${1:-}"
    local data="${2:-}"
    local attempt code

    for attempt in 1 2 3 4 5; do
        if [ -z "$data" ]; then
            code=$(curl -X POST --silent --show-error --max-time 15 -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || echo 000)
        else
            code=$(curl -X POST --silent --show-error --max-time 15 -o /dev/null -w '%{http_code}' "$url" -H 'Content-Type: application/json' --data "$data" 2>/dev/null || echo 000)
        fi

        if [ "$code" -ge 200 ] 2>/dev/null && [ "$code" -lt 300 ] 2>/dev/null; then
            return 0
        fi

        echo "[dply] callback POST to $url returned HTTP $code (attempt $attempt/5)." >&2
        sleep $((attempt * 3))
    done

    return 1
}

function httpPostRawSilently()
{
    local url="${1:-}"
    local data="${2:-}"

    (curl -X POST --silent --max-time 15 --output /dev/null "$url" --data "$data" || true)
}

