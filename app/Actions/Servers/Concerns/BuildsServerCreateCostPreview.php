<?php

declare(strict_types=1);

namespace App\Actions\Servers\Concerns;

use App\Livewire\Forms\ServerCreateForm;
use Illuminate\Support\Collection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsServerCreateCostPreview
{


    /**
     * @param  array{
     *     credentials: Collection<int, mixed>,
     *     regions: list<array<string, mixed>>,
     *     sizes: list<array<string, mixed>>,
     *     region_label: string,
     *     size_label: string
     * }  $catalog
     * @return array{
     *     state: 'available'|'unavailable'|'incomplete',
     *     provider: string,
     *     region: ?string,
     *     size: ?string,
     *     price_monthly: ?float,
     *     price_hourly: ?float,
     *     formatted_price: ?string,
     *     source: ?string,
     *     detail: string,
     *     extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>,
     *     notes: list<string>
     * }
     */
    private function buildCostPreview(ServerCreateForm $form, array $catalog): array
    {
        if ($form->type === 'custom') {
            return [
                'state' => 'unavailable',
                'provider' => 'custom',
                'region' => null,
                'size' => null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Dply cannot estimate pricing for your own VPS. Billing stays with your provider.'),
                'extras' => [],
                'notes' => [__('Billing remains with your current infrastructure provider.')],
            ];
        }

        if ($form->type === 'digitalocean_kubernetes') {
            return $this->buildDoksCostPreview($form, $catalog);
        }

        if ($form->type === 'aws_kubernetes') {
            return $this->buildEksCostPreview($form);
        }

        $size = collect($catalog['sizes'] ?? [])->first(fn (array $option): bool => (string) ($option['value'] ?? '') === $form->size);

        if (! is_array($size)) {
            if ($form->type === 'digitalocean_functions') {
                return [
                    'state' => 'unavailable',
                    'provider' => $form->type,
                    'region' => null,
                    'size' => null,
                    'price_monthly' => null,
                    'price_hourly' => null,
                    'formatted_price' => null,
                    'source' => null,
                    'detail' => __('DigitalOcean Functions pricing depends on invocations, execution time, and memory. Review pricing in DigitalOcean before launch.'),
                    'extras' => [],
                    'notes' => [__('Functions hosts do not use VM region/size catalogs.')],
                ];
            }

            return [
                'state' => 'incomplete',
                'provider' => $form->type,
                'region' => $form->region !== '' ? $form->region : null,
                'size' => $form->size !== '' ? $form->size : null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Choose a region and size to preview estimated provider cost.'),
                'extras' => [],
                'notes' => [],
            ];
        }

        $priceMonthly = is_numeric($size['price_monthly'] ?? null) ? round((float) $size['price_monthly'], 2) : null;
        $priceHourly = is_numeric($size['price_hourly'] ?? null) ? round((float) $size['price_hourly'], 4) : null;

        if ($priceMonthly === null && $priceHourly === null) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => $form->region !== '' ? $form->region : null,
                'size' => $form->size !== '' ? $form->size : null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => $size['pricing_source'] ?? null,
                'detail' => __('Pricing is unavailable for this provider selection right now. Continue only after checking pricing in the provider dashboard if cost matters.'),
                'extras' => $this->buildCostExtras($form),
                'notes' => $this->buildCostNotes($form),
            ];
        }

        $formattedPrice = $priceMonthly !== null
            ? '$'.number_format($priceMonthly, $priceMonthly < 10 ? 2 : 0).'/'.__('mo')
            : '$'.number_format((float) $priceHourly, 4).'/'.__('hr');

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => $form->region !== '' ? $form->region : null,
            'size' => $form->size !== '' ? $form->size : null,
            'price_monthly' => $priceMonthly,
            'price_hourly' => $priceHourly,
            'formatted_price' => $formattedPrice,
            'source' => $size['pricing_source'] ?? 'provider_catalog',
            'detail' => __('Estimated from the provider catalog for the selected size. Taxes, backups, bandwidth, and add-ons may change the final bill.'),
            'extras' => $this->buildCostExtras($form),
            'notes' => $this->buildCostNotes($form),
        ];
    }

    /**
     * @return list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>
     */
    private function buildCostExtras(ServerCreateForm $form): array
    {
        $extras = [];

        if ($form->type === 'digitalocean') {
            $extras[] = [
                'label' => __('Backups'),
                'state' => $form->do_backups ? 'enabled' : 'optional',
                'detail' => $form->do_backups
                    ? __('DigitalOcean backups are enabled and may increase the final bill.')
                    : __('Backups are optional and billed separately if you enable them.'),
                'amount' => null,
                'amount_period' => null,
            ];
            $extras[] = [
                'label' => __('Monitoring'),
                'state' => $form->do_monitoring ? 'included' : 'optional',
                'detail' => __('DigitalOcean monitoring is informational and does not add a separate provider charge.'),
                'amount' => 0.0,
                'amount_period' => 'monthly',
            ];
            $extras[] = [
                'label' => __('IPv6'),
                'state' => $form->do_ipv6 ? 'included' : 'optional',
                'detail' => __('IPv6 does not add a separate line item, but network and bandwidth usage may still vary.'),
                'amount' => 0.0,
                'amount_period' => null,
            ];
        }

        return $extras;
    }

    /**
     * @return list<string>
     */
    private function buildCostNotes(ServerCreateForm $form): array
    {
        $notes = [];

        if ($form->type === 'digitalocean') {
            $notes[] = __('Bandwidth overages, snapshots, and attached storage are not included in this estimate.');
        } else {
            $notes[] = __('Provider taxes, storage, data transfer, and optional services may change the final bill.');
        }

        return $notes;
    }
}
