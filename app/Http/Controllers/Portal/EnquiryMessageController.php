<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Enquiries\EnquiryWorkflow;
use App\Models\Enquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnquiryMessageController extends PortalController
{
    public function store(Request $request, Enquiry $enquiry, EnquiryWorkflow $workflow): RedirectResponse
    {
        $this->authorize('message', $enquiry);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:25600', 'mimes:jpg,jpeg,png,webp,heic,pdf,doc,docx'],
        ], [
            'body.required' => 'Please write a message.',
        ]);

        $workflow->postMessage($enquiry, $request->user(), $data['body'], $request->file('attachments', []));

        return back()->with('success', 'Message sent.');
    }
}
