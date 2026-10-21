<?php

use Illuminate\Support\Facades\Route;

/*
| The API lives under /api (see routes/api.php). The user interface is the separate
| React application in ../frontend, which proxies /api to this server in development.
*/
Route::get('/', fn () => response()->json([
    'name' => 'Demographics & Economy data module',
    'api' => '/api/v1',
    'documentation' => '../docs/architecture.md',
    'frontend' => 'Run the React app in ../frontend (npm run dev) and open http://localhost:5173',
]));
