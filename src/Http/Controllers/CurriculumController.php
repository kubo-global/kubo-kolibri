<?php

namespace KuboKolibri\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use KuboKolibri\Services\CurriculumInstaller;

class CurriculumController extends Controller
{
    /**
     * Install a bundled curriculum from the Settings UI. Headmaster/admin
     * only (enforced by route middleware and re-checked here, matching the
     * pattern of the mapping API).
     */
    public function install(string $slug, CurriculumInstaller $installer): RedirectResponse
    {
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['headmaster', 'admin'])) {
            abort(403);
        }

        $back = redirect(route('settings.index') . '#modules');

        $data = $installer->load($slug);
        if (!$data) {
            return $back->with('error', "Unknown curriculum '{$slug}'.");
        }

        try {
            $stats = $installer->install($data);
        } catch (InvalidArgumentException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('success', sprintf(
            '%s installed: %d added, %d updated, %d already in place.',
            $data['name'],
            $stats['created'],
            $stats['updated'],
            $stats['unchanged'],
        ));
    }
}
