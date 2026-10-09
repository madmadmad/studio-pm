<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Database\Seeder;

// Chat's #general, with every active staff member in it. Safe to run again:
// it only adds whoever isn't in yet (someone joining the studio later).
//
//     php artisan db:seed --class=ChatSeeder --force
class ChatSeeder extends Seeder
{
    public function run(ChatService $chat): void
    {
        $staff = User::whereNull('deactivated_at')->orderBy('id')->get();
        if ($staff->isEmpty()) {
            return;
        }

        $general = Conversation::firstOrCreate(['slug' => 'general'], [
            'type' => Conversation::TYPE_CHANNEL,
            'name' => 'general',
            'description' => 'The whole studio.',
            'created_by' => $staff->firstWhere('role', User::ROLE_SUPER_ADMIN)?->id ?? $staff->first()->id,
        ]);

        foreach ($staff as $user) {
            $chat->join($general, $user);
        }
    }
}
