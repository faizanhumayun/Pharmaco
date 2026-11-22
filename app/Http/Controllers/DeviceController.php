<?php

namespace App\Http\Controllers;

use App\Support\CurrentDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Naming the computer in front of you: "Laptop POS 1", "Laptop Supply".
 *
 * Anyone signed in on a device may name it — it is theirs to recognise — and
 * the name is what the trail then shows beside everything entered on it.
 */
class DeviceController extends Controller
{
    public function update(Request $request, CurrentDevice $current): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60', 'regex:/\S/']]);

        $device = $current->get();
        abort_if($device === null, 409, 'This device could not be recognised. Reload the page and try again.');

        $device->forceFill(['name' => trim(preg_replace('/\s+/', ' ', $data['name']))])->save();

        return response()->json(['name' => $device->label()]);
    }
}
