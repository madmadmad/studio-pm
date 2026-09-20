<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // email and default_hourly_rate are dropped -- Contacts own emails now,
    // and rates come from Services, not a client-level default. The single
    // free-text address is split into structured fields (street/city/
    // state/zip), which is friendlier to fill in and format consistently.
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('address_line1')->nullable()->after('address');
            $table->string('city')->nullable()->after('address_line1');
            $table->string('state')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('state');
        });

        // Best-effort split of the old "street, city, state zip" text into
        // the new columns -- exact for the one real address already on file
        // ("9686 Oak Haven Ct., Perrysburg, OH 43551"); anything that
        // doesn't fit that shape just keeps its full text as the street
        // line, which the client can then re-split by hand if they want to.
        Company::whereNotNull('address')->where('address', '!=', '')->get()->each(function (Company $company) {
            $parts = array_map('trim', explode(',', $company->address));

            if (count($parts) >= 3) {
                $stateZip = array_pop($parts);
                $city = array_pop($parts);
                $street = implode(', ', $parts);
                [$state, $postalCode] = array_pad(explode(' ', trim($stateZip), 2), 2, null);

                $company->forceFill([
                    'address_line1' => $street,
                    'city' => $city,
                    'state' => $state,
                    'postal_code' => $postalCode,
                ])->save();
            } else {
                $company->forceFill(['address_line1' => $company->address])->save();
            }
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['address', 'email', 'default_hourly_rate']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->decimal('default_hourly_rate', 8, 2)->nullable();
        });

        Company::query()->get()->each(function (Company $company) {
            $line = trim(implode(', ', array_filter([
                $company->address_line1,
                $company->city,
                trim(($company->state ?? '').' '.($company->postal_code ?? '')),
            ])));

            $company->forceFill(['address' => $line ?: null])->save();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['address_line1', 'city', 'state', 'postal_code']);
        });
    }
};
