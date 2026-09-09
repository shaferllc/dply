{{--
    Renders nothing. Both workspace nav rows are gone: the Services row first,
    then Compute (Sites/Servers) — everything they held is in the header's
    Compute/Apps dropdown and its responsive twin, so the rows were a second
    navigation restating the first.

    Kept as a no-op because ~16 pages call <x-workspace-nav />; delete those
    calls (and this file, plus x-compute-index-nav / x-services-index-nav)
    once it is clear the rows are not coming back.
--}}
