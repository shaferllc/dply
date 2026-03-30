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

# Send a POST request and return the actual exit code
function httpPost()
{
    local url="${1:-}"
    local data="${2:-}"

    if [ -z "$data" ]; then
        curl -X POST --silent --max-time 15 --output /dev/null "$url"
    else
        curl -X POST --silent --max-time 15 --output /dev/null "$url" -H 'Content-Type: application/json' --data "$data"
    fi
}

function httpPostRawSilently()
{
    local url="${1:-}"
    local data="${2:-}"

    (curl -X POST --silent --max-time 15 --output /dev/null "$url" --data "$data" || true)
}

