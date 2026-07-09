<?php

namespace App\Observers;

use App\Models\CustomerAddress;

class CustomerAddressObserver
{
    public function created(CustomerAddress $address): void
    {
        $this->refreshCustomerSearchText($address);
    }

    public function updated(CustomerAddress $address): void
    {
        $this->refreshCustomerSearchText($address);
    }

    public function deleted(CustomerAddress $address): void
    {
        $this->refreshCustomerSearchText($address);
    }

    private function refreshCustomerSearchText(CustomerAddress $address): void
    {
        $customer = $address->customer;

        if (! $customer) {
            return;
        }

        app(CustomerObserver::class)->refreshSearchText($customer);
    }
}
