<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\CustomerCodeGeneratorService;
use Illuminate\Support\Facades\App;

class CustomerObserver
{
    public function creating(Customer $customer): void
    {
        $service = App::make(CustomerCodeGeneratorService::class);

        $customer->customer_code = $customer->customer_code ?? $service->generate($customer->store_id);

        if (! $customer->customer_code) {
            $customer->customer_code = $service->generate($customer->store_id);
        }

        $this->normalizeDocumentNumber($customer);
        $this->generateSearchText($customer);

        if (auth()->check()) {
            $customer->store_id = $customer->store_id ?? auth()->user()->store_id;
            $customer->created_by = $customer->created_by ?? auth()->id();
        }
    }

    public function updating(Customer $customer): void
    {
        $this->normalizeDocumentNumber($customer);
        $this->generateSearchText($customer);

        if (auth()->check()) {
            $customer->updated_by = auth()->id();
        }
    }

    private function normalizeDocumentNumber(Customer $customer): void
    {
        if ($customer->isDirty('document_number')) {
            $customer->document_number_normalized = preg_replace('/[^0-9]/', '', $customer->document_number);
        }
    }

    private function generateSearchText(Customer $customer): void
    {
        $parts = array_filter([
            $customer->display_name,
            $customer->document_number_normalized,
            $customer->customer_code,
        ]);

        $text = mb_strtolower(implode(' ', $parts), 'UTF-8');

        $text = preg_replace('/[áàâãä]/u', 'a', $text);
        $text = preg_replace('/[éèêë]/u', 'e', $text);
        $text = preg_replace('/[íìîï]/u', 'i', $text);
        $text = preg_replace('/[óòôõö]/u', 'o', $text);
        $text = preg_replace('/[úùûü]/u', 'u', $text);
        $text = preg_replace('/[ñ]/u', 'n', $text);

        $customer->search_text = $text;
    }
}
