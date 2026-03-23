@if ($errors->isNotEmpty())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
        <p class="font-medium">{{ __('There are errors in the form. Please fix them below.') }}</p>
        @if ($errors->count() > 0 && $errors->count() <= 3)
            <ul class="mt-1 list-disc list-inside text-red-700">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
