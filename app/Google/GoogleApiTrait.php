<?php

namespace App\Google;


use App\External\ApiProgress;
use Illuminate\Support\Collection;

trait GoogleApiTrait
{
    protected function paginate($key, $request, ApiProgress $progress = null): Collection
    {
        $nextPageToken = "";
        $collection = collect();
        $stepCount = 0;
        do {
            $response = json_decode($request($nextPageToken)->getBody(), true);
            if (array_key_exists($key, $response)) {
                $collection = $collection->merge($response[$key]);
            } else {
                return collect($response);  // TODO Might need to raise exception here, don't know why we'd want this? Empty return instead?
            }

            if (!array_key_exists("nextPageToken", $response)) break;

            $nextPageToken = $response["nextPageToken"];

            $stepCount++;

            if (!is_null($progress)) {
                $lastStep = $nextPageToken == "";
                $progress->setProgress($stepCount, $lastStep ? $stepCount : $stepCount + 1);
            }
        } while ($nextPageToken != "");

        return $collection;
    }
}
