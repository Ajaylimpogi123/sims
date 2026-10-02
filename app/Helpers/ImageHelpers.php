<?php

namespace App\Helpers;

class ImageHelper
{
    public static function getImageUrl($path)
    {
        if (! $path) {
            return asset('images/logo/Westpoint.png'); // Default image
        }

        return asset('storage/'.$path);
    }
}
