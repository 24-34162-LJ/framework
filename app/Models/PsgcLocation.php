<?php

/*
 * FILE PURPOSE
 * File   : app/Models/PsgcLocation.php
 * Feature: Delivery address lookup
 * Purpose: One PH province, city/town or barangay from the PSA's PSGC list, used to suggest
 *          delivery addresses without an outside maps service.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PsgcLocation extends Model
{
    protected $table = 'psgc_locations';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Lower-case letters and digits only, so "Mataas na Kahoy", "mataasnakahoy" and "MATAAS-NA-KAHOY" all match.
     */
    public static function normalize(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($text))) ?? '';
    }

    /**
     * Best matches for what was typed. Every word must appear somewhere in the place's full label,
     * so "santol batangas" and "mataas na kahoy" both work. Towns rank above barangays.
     *
     * @return Collection<int, self>
     */
    public static function search(string $query, int $limit = 8): Collection
    {
        $tokens = collect(preg_split('/[\s,]+/', $query) ?: [])
            ->map(fn (string $word): string => self::normalize($word))
            ->filter()
            ->take(6)
            ->values();
        $joined = self::normalize($query);
        if ($tokens->isEmpty() || strlen($joined) < 2) {
            return collect();
        }

        return self::query()
            ->where(function (Builder $where) use ($tokens): void {
                foreach ($tokens as $token) {
                    $where->where('search_key', 'like', '%'.$token.'%');
                }
            })
            ->orderByRaw('CASE WHEN name_key = ? THEN 0 WHEN name_key LIKE ? THEN 1 ELSE 2 END', [$joined, $joined.'%'])
            ->orderByRaw("CASE type WHEN 'city' THEN 0 WHEN 'municipality' THEN 0 WHEN 'province' THEN 1 ELSE 2 END")
            ->orderBy('label')
            ->limit($limit)
            ->get(['code', 'type', 'name', 'label']);
    }
}
