<?php

namespace Tests\Unit;

use App\Services\AttendanceService;
use App\Services\EmployeeDeviceService;
use PHPUnit\Framework\TestCase;

/**
 * True unit test: haversineDistanceMeters() is a pure trigonometric formula over 4 floats -
 * no database, no HTTP. EmployeeDeviceService is only passed because the constructor
 * requires it; this particular method never touches it.
 */
class AttendanceServiceGeofenceTest extends TestCase
{
    private function service(): AttendanceService
    {
        return new AttendanceService(new EmployeeDeviceService());
    }

    public function test_distance_between_identical_coordinates_is_zero(): void
    {
        $service = $this->service();

        $this->assertEqualsWithDelta(
            0.0,
            $service->haversineDistanceMeters(33.5138, 36.2765, 33.5138, 36.2765),
            0.0001
        );
    }

    public function test_distance_for_a_pure_latitude_offset_matches_the_closed_form_great_circle_length(): void
    {
        $service = $this->service();

        // Moving along a meridian (same longitude) is a great-circle arc, so its length has
        // an exact closed form: earthRadius * deltaLatitudeInRadians - independent of the
        // haversine formula being tested, which makes this a real correctness check rather
        // than the method just checking itself.
        $earthRadiusMeters = 6371000;
        $deltaLatitudeDegrees = 0.01;
        $expectedMeters = $earthRadiusMeters * deg2rad($deltaLatitudeDegrees);

        $distance = $service->haversineDistanceMeters(33.50, 36.30, 33.50 + $deltaLatitudeDegrees, 36.30);

        $this->assertEqualsWithDelta($expectedMeters, $distance, 0.01);
    }

    public function test_distance_grows_monotonically_as_points_move_further_apart(): void
    {
        $service = $this->service();

        $near = $service->haversineDistanceMeters(33.5138, 36.2765, 33.5140, 36.2765);
        $far = $service->haversineDistanceMeters(33.5138, 36.2765, 33.6000, 36.2765);

        $this->assertGreaterThan($near, $far);
    }
}
