{{-- Thin wrapper — actual journey UI lives in the BuildJourney Livewire
     component so the polling + per-step log streaming can live in one
     place rather than threaded through three different parent components.
     Callers still pass $deployment so the wrapper can be swapped back to
     a static partial later without changing call sites. --}}
@livewire('edge.build-journey', ['deploymentId' => $deployment->id], key('edge-build-journey-'.$deployment->id))
