<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentMethodController extends Controller
{
    private function map($row): array
    {
        return [
            'id' => (int) $row->id,
            'bankName' => $row->bank_name,
            'accountName' => $row->account_name,
            'accountNumber' => $row->account_number,
            'iban' => $row->iban,
            'swiftCode' => $row->swift_code,
            'currency' => $row->currency,
            'isActive' => (bool) $row->is_active,
            'createdAt' => $row->created_at ?? null,
            'updatedAt' => $row->updated_at ?? null,
        ];
    }

    public function index(Request $request)
    {
        $query = DB::table('bank_accounts')->orderBy('currency')->orderBy('id');
        if (filter_var($request->query('activeOnly', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('is_active', 1);
        }
        if ($currency = strtoupper(trim((string) $request->query('currency', '')))) {
            $query->where('currency', $currency);
        }
        return ApiResponse::ok($query->get()->map(fn ($row) => $this->map($row))->values());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bankName' => 'required|string|min:2|max:180',
            'accountName' => 'required|string|min:2|max:180',
            'accountNumber' => 'required|string|min:4|max:100',
            'iban' => 'nullable|string|max:100',
            'swiftCode' => 'nullable|string|max:32',
            'currency' => 'required|string|size:3',
            'isActive' => 'nullable|boolean',
        ]);

        $id = DB::table('bank_accounts')->insertGetId([
            'bank_name' => trim($validated['bankName']),
            'account_name' => trim($validated['accountName']),
            'account_number' => trim($validated['accountNumber']),
            'iban' => isset($validated['iban']) ? trim($validated['iban']) : null,
            'swift_code' => isset($validated['swiftCode']) ? trim($validated['swiftCode']) : null,
            'currency' => strtoupper(trim($validated['currency'])),
            'is_active' => $validated['isActive'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ApiResponse::ok($this->map(DB::table('bank_accounts')->find($id)), 'Payment method created');
    }

    public function update(Request $request, $id)
    {
        $id = (int) $id;
        if (!DB::table('bank_accounts')->where('id', $id)->exists()) {
            return ApiResponse::fail('Payment method not found', 404);
        }

        $validated = $request->validate([
            'bankName' => 'required|string|min:2|max:180',
            'accountName' => 'required|string|min:2|max:180',
            'accountNumber' => 'required|string|min:4|max:100',
            'iban' => 'nullable|string|max:100',
            'swiftCode' => 'nullable|string|max:32',
            'currency' => 'required|string|size:3',
            'isActive' => 'nullable|boolean',
        ]);

        DB::table('bank_accounts')->where('id', $id)->update([
            'bank_name' => trim($validated['bankName']),
            'account_name' => trim($validated['accountName']),
            'account_number' => trim($validated['accountNumber']),
            'iban' => isset($validated['iban']) ? trim($validated['iban']) : null,
            'swift_code' => isset($validated['swiftCode']) ? trim($validated['swiftCode']) : null,
            'currency' => strtoupper(trim($validated['currency'])),
            'is_active' => $validated['isActive'] ?? true,
            'updated_at' => now(),
        ]);

        return ApiResponse::ok($this->map(DB::table('bank_accounts')->find($id)), 'Payment method updated');
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate(['isActive' => 'required|boolean']);
        $id = (int) $id;
        if (!DB::table('bank_accounts')->where('id', $id)->exists()) {
            return ApiResponse::fail('Payment method not found', 404);
        }
        DB::table('bank_accounts')->where('id', $id)->update([
            'is_active' => $validated['isActive'] ? 1 : 0,
            'updated_at' => now(),
        ]);
        return ApiResponse::ok($this->map(DB::table('bank_accounts')->find($id)), 'Payment method status updated');
    }

    public function destroy($id)
    {
        $id = (int) $id;
        $account = DB::table('bank_accounts')->where('id', $id)->first();
        if (!$account) {
            return ApiResponse::fail('Payment method not found', 404);
        }

        $methodKey = 'bank_account:' . $id;
        $referenced = DB::table('orders')->where('payment_method', $methodKey)->exists()
            || DB::table('orders')->where('payment_reference', 'like', '%' . $account->account_number . '%')->exists()
            || DB::table('registrations')->where('payment_method', $methodKey)->exists();

        if ($referenced) {
            DB::table('bank_accounts')->where('id', $id)->update(['is_active' => 0, 'updated_at' => now()]);
            return ApiResponse::ok($this->map(DB::table('bank_accounts')->find($id)), 'Payment method is in use and was deactivated');
        }

        DB::table('bank_accounts')->where('id', $id)->delete();
        return ApiResponse::ok(['id' => $id], 'Payment method deleted');
    }
}
