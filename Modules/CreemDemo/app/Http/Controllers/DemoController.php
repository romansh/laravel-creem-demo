<?php

namespace Modules\CreemDemo\Http\Controllers;

use Illuminate\Routing\Controller;

/**
 * Demo Controller for Creem Module.
 * 
 * Handles main demo pages.
 */
class DemoController extends Controller
{
    /**
     * Display the demo dashboard.
     */
    public function index()
    {
        return view('creemdemo::index');
    }

    /**
     * Display success page after payment.
     */
    public function success()
    {
        // Redirect back to the demo index to keep the UI (Livewire + Alpine) active.
        return redirect()->route('creem-demo.index')->with('success', 'Payment successful!');
    }

    /**
     * Transactions page.
     */
    public function transactions()
    {
        return view('creemdemo::transactions.index');
    }
}
