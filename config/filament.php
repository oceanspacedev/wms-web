<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Disk used by FileUpload, ImageColumn and ImageEntry when no disk is
    | specified. Keep the same fallback as `filesystems.default` so photos
    | uploaded through the API and shown in the panel use the same disk.
    |
    */

    'default_filesystem_disk' => env('FILESYSTEM_DISK', 'public'),

];
