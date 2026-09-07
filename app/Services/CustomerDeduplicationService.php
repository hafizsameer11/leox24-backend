<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class CustomerDeduplicationService
{
    /**
     * Find or create customer with deduplication check.
     */
    public function findOrCreateCustomer(array $data, ?int $companyId = null): Customer
    {
        return DB::transaction(function () use ($data, $companyId) {
            // Check for existing customer by email, phone, or VAT
            $existing = Customer::where(function ($query) use ($data) {
                if (isset($data['email'])) {
                    $query->where('email', $data['email']);
                }
                if (isset($data['phone'])) {
                    $query->orWhere('phone', $data['phone']);
                }
                if (isset($data['vat'])) {
                    $query->orWhere('vat', $data['vat']);
                }
            })->first();

            if ($existing) {
                // Update existing customer if needed
                $updateData = $data;
                unset($updateData['created_by']);
                $existing->update(array_merge($updateData, [
                    'company_id' => $companyId ?? $existing->company_id,
                ]));
                return $existing;
            }

            // Create new customer
            return Customer::create(array_merge($data, [
                'company_id' => $companyId,
            ]));
        });
    }

    /**
     * Merge duplicate customers.
     */
    public function mergeCustomers(array $customerIds, int $primaryCustomerId): Customer
    {
        return DB::transaction(function () use ($customerIds, $primaryCustomerId) {
            $primary = Customer::findOrFail($primaryCustomerId);

            // Get all customers to merge
            $customers = Customer::whereIn('id', $customerIds)
                ->where('id', '!=', $primaryCustomerId)
                ->get();

            // Merge data (keep non-null values from primary)
            foreach ($customers as $customer) {
                // Update primary with missing data
                if (!$primary->first_name && $customer->first_name) {
                    $primary->first_name = $customer->first_name;
                }
                if (!$primary->last_name && $customer->last_name) {
                    $primary->last_name = $customer->last_name;
                }
                if (!$primary->address && $customer->address) {
                    $primary->address = $customer->address;
                }
                if (!$primary->vat && $customer->vat) {
                    $primary->vat = $customer->vat;
                }
                // `notes` is also the name of the polymorphic notes relation,
                // so read the legacy customer-profile field explicitly.
                $customerProfileNotes = $customer->getRawOriginal('notes');
                if ($customerProfileNotes) {
                    $primaryProfileNotes = $primary->getRawOriginal('notes');
                    $primary->setAttribute('notes', ($primaryProfileNotes ? $primaryProfileNotes . "\n\n" : '') . $customerProfileNotes);
                }
            }

            $primary->save();

            // Delete merged customers
            Customer::whereIn('id', $customerIds)
                ->where('id', '!=', $primaryCustomerId)
                ->delete();

            return $primary;
        });
    }
}
