<?php

namespace App\Quality\Actor;

use Illuminate\Http\Request;

interface ActorAuthenticatorInterface
{
    public function authenticate(Request $request, string $actor): void;
}

