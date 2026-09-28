<?php

namespace App\Services;

use App\Integrations\Drivers\GenericDriver;
use App\Integrations\Drivers\ProjectIntegrationDriver;
use App\Models\Project;

class ProjectIntegrationService
{
    private array $drivers = [];

    public function getDriver(Project $project): ProjectIntegrationDriver
    {
        $driverClass = $project->driver_class ?? $this->getDefaultDriver($project);

        if (!isset($this->drivers[$driverClass])) {
            if (!class_exists($driverClass)) {
                // Fallback to generic driver
                $driverClass = GenericDriver::class;
            }

            $this->drivers[$driverClass] = app($driverClass);
        }

        return $this->drivers[$driverClass];
    }

    private function getDefaultDriver(Project $project): string
    {
        // Map project slug to driver
        $map = [
            'optyshop' => GenericDriver::class, // Will be replaced with OptyShopDriver
            'tg-calabria' => GenericDriver::class,
            'mydoctor' => GenericDriver::class,
            'vista-express' => GenericDriver::class,
        ];

        return $map[$project->slug] ?? GenericDriver::class;
    }
}
