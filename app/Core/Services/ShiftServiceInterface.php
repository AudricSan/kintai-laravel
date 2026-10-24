<?php

declare(strict_types=1);

namespace kintai\Core\Services;

interface ShiftServiceInterface
{
    /**
     * Get filtered shifts for a list of stores and a specific month.
     *
     * @param int[] $storeIds
     * @param string $month YYYY-MM
     * @param int $filterStoreId
     * @param int $filterUserId
     * @return array
     */
    public function getShiftsForAdmin(array $storeIds, string $month, int $filterStoreId = 0, int $filterUserId = 0): array;

    /**
     * Get a map of user IDs to display names.
     */
    public function getUsersMap(): array;

    /**
     * Get a map of shift type IDs to shift type data.
     */
    public function getShiftTypesMap(): array;

    /**
     * Get shifts organized by date for a list of users.
     */
    public function getCalendarData(array $userIds, string $startDate, string $endDate, int $filterStoreId = 0): array;

    /**
     * Get approved timeoff requests organized by date for a list of users.
     */
    public function getTimeoffForCalendar(array $userIds, string $startDate, string $endDate): array;
}
