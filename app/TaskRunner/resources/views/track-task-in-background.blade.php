<x-task-shell-defaults :exit-immediately="false" />

@include('task-runner::common-functions')

@php
    $taskModel = $actualTask->getTaskModel();
    $finishedWebhookUrl = $taskModel?->webhookUrl('markAsFinished');
    $failedWebhookUrl = $taskModel?->webhookUrl('markAsFailed');
    $timeoutWebhookUrl = $taskModel?->webhookUrl('markAsTimedOut');
@endphp

DIRECTORY=$(dirname "$0")
FILENAME=$(basename "$0")
EXT="${FILENAME##*.}"
PATH_ACTUAL_SCRIPT="$DIRECTORY/${FILENAME%.*}-original.$EXT"
currentscript="$0"

# Writing actual script to $PATH_ACTUAL_SCRIPT

function finish {
    #"Securely shredding ${currentscript}" and "${PATH_ACTUAL_SCRIPT}"
    shred -u ${currentscript};
    shred -u ${PATH_ACTUAL_SCRIPT}
}

cat > $PATH_ACTUAL_SCRIPT << '{{ $eof }}'

@includeWhen($actualTask->callbackUrl(), 'task-runner::common-functions')

{!! str_replace(\App\Modules\TaskRunner\Helper::eof(), '', $actualTask->getScript()) !!}

{{ $eof }}

# Log the actual script content being executed
echo "=== ACTUAL SCRIPT CONTENT ===" | tee $PATH_ACTUAL_SCRIPT.log
cat $PATH_ACTUAL_SCRIPT | tee -a $PATH_ACTUAL_SCRIPT.log
echo "=== END SCRIPT CONTENT ===" | tee -a $PATH_ACTUAL_SCRIPT.log

# Store the script content in the database
SCRIPT_CONTENT=$(cat $PATH_ACTUAL_SCRIPT)
echo "Storing script content in database..." | tee -a $PATH_ACTUAL_SCRIPT.log
httpPostSilently "{!! $actualTask->getTaskModel()->webhookUrl('updateOutput') !!}" "{\"script_content\":\"$(echo "$SCRIPT_CONTENT" | sed 's/"/\\"/g' | tr '\n' '\\n')\"}"

# Log the command being executed
@if($actualTask->getTimeout())
EXECUTION_COMMAND="timeout {{ $actualTask->getTimeout() }}s bash \$PATH_ACTUAL_SCRIPT"
@else
EXECUTION_COMMAND="bash \$PATH_ACTUAL_SCRIPT"
@endif
echo "Executing command: $EXECUTION_COMMAND" | tee -a $PATH_ACTUAL_SCRIPT.log

# Include callback functions for wrapper
@includeWhen($actualTask->callbackUrl(), 'task-runner::common-functions')

# Running actual script and capturing output
@if($actualTask->getTimeout())
timeout {{ $actualTask->getTimeout() }}s bash $PATH_ACTUAL_SCRIPT 2>&1 | while IFS= read -r line; do
@else
bash $PATH_ACTUAL_SCRIPT 2>&1 | while IFS= read -r line; do
@endif
    printf '%s\n' "$line" | tee -a $PATH_ACTUAL_SCRIPT.log
    ESCAPED_LINE=$(printf '%s' "$line" | sed 's/\\/\\\\/g; s/"/\\"/g')
    httpPostSilently "{!! $actualTask->getTaskModel()->webhookUrl('updateOutput') !!}" "{\"output\":\"${ESCAPED_LINE}\",\"append_newline\":true}"
done
EXIT_CODE=${PIPESTATUS[0]}

# Log final exit code
echo "Final script execution completed with exit code: $EXIT_CODE" | tee -a $PATH_ACTUAL_SCRIPT.log

# Task completion callbacks
@if($actualTask->callbackUrl())
    if [[ $EXIT_CODE -eq 0 ]]; then
        # Task finished successfully
        echo "Task completed successfully, calling finished webhook..." | tee -a $PATH_ACTUAL_SCRIPT.log
        if httpPost "{!! $finishedWebhookUrl !!}" "{\"exit_code\":0}"; then
            echo "Finished webhook called successfully" | tee -a $PATH_ACTUAL_SCRIPT.log
        else
            echo "Finished webhook failed, trying artisan command..." | tee -a $PATH_ACTUAL_SCRIPT.log
            php artisan task:complete {{ $actualTask->getTaskModel()->id }} --exit-code=0 || echo "Artisan command also failed" | tee -a $PATH_ACTUAL_SCRIPT.log
        fi
    elif [[ $EXIT_CODE -eq 124 ]]; then
        # Task timed out
        echo "Task timed out, calling timeout webhook..." | tee -a $PATH_ACTUAL_SCRIPT.log
        if httpPost "{!! $timeoutWebhookUrl !!}" "{\"exit_code\":124}"; then
            echo "Timeout webhook called successfully" | tee -a $PATH_ACTUAL_SCRIPT.log
        else
            echo "Timeout webhook failed, trying artisan command..." | tee -a $PATH_ACTUAL_SCRIPT.log
            php artisan task:complete {{ $actualTask->getTaskModel()->id }} --exit-code=124 || echo "Artisan command also failed" | tee -a $PATH_ACTUAL_SCRIPT.log
        fi
    else
        # Task failed with exit status $EXIT_CODE
        echo "Task failed with exit code $EXIT_CODE, calling failed webhook..." | tee -a $PATH_ACTUAL_SCRIPT.log
        if httpPost "{!! $failedWebhookUrl !!}" "{\"exit_code\":$EXIT_CODE}"; then
            echo "Failed webhook called successfully" | tee -a $PATH_ACTUAL_SCRIPT.log
        else
            echo "Failed webhook failed, trying artisan command..." | tee -a $PATH_ACTUAL_SCRIPT.log
            php artisan task:complete {{ $actualTask->getTaskModel()->id }} --exit-code=$EXIT_CODE || echo "Artisan command also failed" | tee -a $PATH_ACTUAL_SCRIPT.log
        fi
    fi
@endif
