<?php

namespace App\Console\Commands;

use App\Models\Professional;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateStaffAccount extends Command
{
    protected $signature = 'staff:account
        {email : Login email for the account}
        {--professional= : Existing professional id to attach the account to}
        {--name= : Display name (required when creating an admin account)}
        {--admin : Make this account an administrator}
        {--password= : Password (a random one is generated and shown if omitted)}';

    protected $description = 'Create or update a doctor-portal account for a professional or admin';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $password = $this->option('password') ?: Str::random(12);

        if ($this->option('professional')) {
            $professional = Professional::find($this->option('professional'));
            if (!$professional) {
                $this->error('Professional not found: ' . $this->option('professional'));
                return self::FAILURE;
            }
        } elseif ($this->option('admin')) {
            if (!$this->option('name')) {
                $this->error('--name is required when creating an admin account.');
                return self::FAILURE;
            }
            // Admin accounts live as hidden professional rows (not bookable)
            $professional = Professional::where('email', $email)->first();
            if (!$professional) {
                $professional = Professional::firstOrNew(['id' => 'staff_' . Str::slug($this->option('name'))]);
            }
            $professional->fill([
                'name' => $this->option('name'),
                'specialty' => 'Administración',
                'consultation_price' => 0,
                'consultation_duration_minutes' => 30,
                'active' => false,
            ]);
        } else {
            $this->error('Pass --professional=<id> or --admin.');
            return self::FAILURE;
        }

        $professional->email = $email;
        $professional->password = Hash::make($password);
        $professional->role = $this->option('admin') ? 'admin' : 'professional';
        $professional->save();

        $this->info("Account ready for {$professional->name} <{$email}> (role: {$professional->role})");
        if (!$this->option('password')) {
            $this->warn("Generated password: $password");
        }

        return self::SUCCESS;
    }
}
