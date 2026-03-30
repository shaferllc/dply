<x-streamline::task-shell-defaults :set-options="$setOptions" />

@include('task-runner::task-runner.common-functions')

# Task Shell Script

# Example usage:
#   bash this-script.sh

echo "Task Shell started at $(date)"

# Place your task logic here
# For example, run a command and capture output
OUTPUT=$(echo "Hello from Task Shell")
echo "$OUTPUT"

# Optionally, handle exit codes and status reporting
EXIT_CODE=$?
if [[ $EXIT_CODE -eq 0 ]]; then
    echo "Task completed successfully."
else
    echo "Task failed with exit code $EXIT_CODE."
fi

exit $EXIT_CODE
