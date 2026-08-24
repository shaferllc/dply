{{-- Title + the provider's scope requirements. Shared by the inline form and
     the modal so the two cannot describe different scopes. --}}
                        <div>
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Add a :name personal access token', ['name' => $provider['name']]) }}</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                                @if ($provider['id'] === 'github')
                                    {{ __('Classic PATs need repo and admin:repo_hook scopes. Fine-grained tokens need Contents (Read), Metadata (Read), and Webhooks (Read & Write) for the target repositories.') }}
                                @elseif ($provider['id'] === 'gitlab')
                                    {{ __('Token needs the api scope. Group-scoped tokens cover every project under that group.') }}
                                @else
                                    {{ __('App password or workspace access token with repository:read and webhook permissions.') }}
                                @endif
                            </p>
                        </div>
