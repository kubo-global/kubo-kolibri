<?php

namespace KuboKolibri\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class ReconcileCommand extends Command
{
    protected $signature = 'kolibri:reconcile {--dry-run : Show what would change without writing}';

    protected $description = 'Bring the local Kolibri install into the configuration KUBO expects (idempotent).';

    public function handle(): int
    {
        $url = rtrim(config('kubo-kolibri.kolibri_url') ?? '', '/');
        $username = config('kubo-kolibri.kolibri_username');
        $password = config('kubo-kolibri.kolibri_password');

        if (!$url || !$username || !$password) {
            $this->error('KOLIBRI_URL, KOLIBRI_USERNAME, KOLIBRI_PASSWORD must be set.');
            return self::FAILURE;
        }

        if (!$this->checkReachable($url)) {
            return self::FAILURE;
        }

        $facility = $this->fetchFacility($url);
        if (!$facility) {
            return self::FAILURE;
        }

        if (!$this->runReconcileScript($username, $password)) {
            return self::FAILURE;
        }

        return $this->verifyLogin($url, $facility, $username, $password)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function checkReachable(string $url): bool
    {
        $this->info("Checking Kolibri at {$url}...");
        try {
            $resp = Http::timeout(5)->get("{$url}/api/public/info/");
            if (!$resp->ok()) {
                $this->error("  Kolibri responded with HTTP {$resp->status()}");
                return false;
            }
        } catch (\Throwable $e) {
            $this->error("  Kolibri unreachable: {$e->getMessage()}");
            return false;
        }
        $this->line('  reachable');
        return true;
    }

    private function fetchFacility(string $url): ?array
    {
        $facilities = Http::timeout(5)->get("{$url}/api/public/v1/facility/")->json();
        if (!is_array($facilities) || count($facilities) === 0) {
            $this->error('  No facility — Kolibri is unprovisioned. Run the setup wizard.');
            return null;
        }
        if (count($facilities) > 1) {
            $this->error('  Multiple facilities found — KUBO expects exactly one. Aborting.');
            return null;
        }
        $f = $facilities[0];
        $this->line("  facility: {$f['name']} ({$f['id']})");
        return $f;
    }

    private function runReconcileScript(string $username, string $password): bool
    {
        $manageCmd = $this->detectManageCmd();
        if (!$manageCmd) {
            $this->error('Could not find `kolibri` or `python3 -m kolibri` on PATH.');
            return false;
        }

        $script = $this->buildReconcileScript($username, $password, (bool) $this->option('dry-run'));

        if ($this->option('dry-run')) {
            $this->line('[dry-run] would run via kolibri manage shell:');
            $this->line($script);
            return true;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'kolibri-reconcile-');
        file_put_contents($tmp, $script);

        try {
            $process = Process::fromShellCommandline("{$manageCmd} manage shell < " . escapeshellarg($tmp));
            $process->setTimeout(60);
            $process->run(function ($type, $buf) {
                $this->getOutput()->write($buf);
            });
            if (!$process->isSuccessful()) {
                $this->error('Reconcile script failed.');
                return false;
            }
        } finally {
            @unlink($tmp);
        }

        return true;
    }

    private function detectManageCmd(): ?string
    {
        foreach (['kolibri', 'python3 -m kolibri'] as $cmd) {
            $p = Process::fromShellCommandline("{$cmd} --version");
            $p->setTimeout(5);
            try {
                $p->run();
            } catch (\Throwable $e) {
                continue;
            }
            if ($p->isSuccessful()) {
                return $cmd;
            }
        }
        return null;
    }

    private function buildReconcileScript(string $username, string $password, bool $dryRun): string
    {
        $u = addslashes($username);
        $p = addslashes($password);
        $dry = $dryRun ? 'True' : 'False';

        return <<<PY
from kolibri.core.device.models import DeviceSettings, DevicePermissions
from kolibri.core.auth.models import Facility, FacilityUser

DRY_RUN = {$dry}

ds = DeviceSettings.objects.first()
if ds is None:
    print("device: no settings row (server-mode install — leaving alone)")
elif not ds.allow_other_browsers_to_connect:
    if not DRY_RUN:
        ds.allow_other_browsers_to_connect = True
        ds.save()
    print("device: set allow_other_browsers_to_connect=True")
else:
    print("device: ok")

facility = Facility.get_default_facility()
username = "{$u}"
password = "{$p}"

user = FacilityUser.objects.filter(username=username, facility=facility).first()
if user is None:
    if not DRY_RUN:
        user = FacilityUser.objects.create(username=username, full_name=username, facility=facility)
        user.set_password(password)
        user.save()
        DevicePermissions.objects.create(user=user, is_superuser=True, can_manage_content=True)
    print(f"admin: created superuser {username}")
else:
    needs_password_reset = not user.check_password(password)
    has_super = DevicePermissions.objects.filter(user=user, is_superuser=True).exists()
    if needs_password_reset and not DRY_RUN:
        user.set_password(password)
        user.save()
    if not has_super and not DRY_RUN:
        DevicePermissions.objects.update_or_create(
            user=user,
            defaults={"is_superuser": True, "can_manage_content": True},
        )
    if needs_password_reset:
        print(f"admin: reset password for {username}")
    if not has_super:
        print(f"admin: granted superuser to {username}")
    if not needs_password_reset and has_super:
        print(f"admin: {username} ok")
PY;
    }

    private function verifyLogin(string $url, array $facility, string $username, string $password): bool
    {
        $this->info('Verifying HTTP login...');
        $resp = Http::asJson()->post("{$url}/api/auth/session/", [
            'username' => $username,
            'password' => $password,
            'facility' => $facility['id'],
        ]);
        if (!$resp->ok()) {
            $this->error("  Login failed (HTTP {$resp->status()}): " . $resp->body());
            return false;
        }
        $this->info('  login OK');
        return true;
    }
}
