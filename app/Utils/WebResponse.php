<?php

namespace App\Utils;

use Exception;
use Inertia\Inertia;

class WebResponse
{
    public static function response($result, $redirect = null)
    {
        if ($result instanceof Exception) {
            return back()->withErrors(['errors' => $result->getMessage()]);
        }

        if (is_null($redirect)) {
            return redirect()->back();
        }

        if (is_array($redirect)) {
            [$routeName, $params] = $redirect;
            return Inertia::location(route($routeName, $params));
        }

        return Inertia::location(route($redirect));
    }


    public static function inertia($result, $redirectRoute, $param = null)
    {
        if ($result instanceof Exception) {
            return back()->withErrors('errors', $result->getMessage());
        } else {
            return Inertia::location(route($redirectRoute, $param));
        }
    }

    public static function inertiaRender($result, $render, $param = [])
    {
        if ($result instanceof Exception) {
            return back()->withErrors('errors', $result->getMessage());
        } else {
            return Inertia::render($render, $param ?? $result);
        }
    }

    public static function json($result, $message = 'Success', $status = 200)
    {
        if ($result instanceof Exception) {
            return response()->json([
                "message" => $result->getMessage(),
                "data" => $result,
            ], 400);
        } else {
            return response()->json([
                "message" => $message,
                "data" => $result,
            ], $status);
        }
    }
}
