// --- Utility Functions ---
let pendingCancellationEventId = null; // ID stored when confirmation is shown

// Utility function to safely escape HTML
function escapeHtml(unsafe) {
    if (typeof unsafe !== 'string') {
        return unsafe === null || typeof unsafe === 'undefined' ? '' : String(unsafe);
    }
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

// --- Toast System ---
function showToast(message, type = 'info') {
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();
    const iconMap = {
        success: { icon: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>', bg: 'bg-green-50 border-green-200', text: 'text-green-700' },
        error: { icon: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>', bg: 'bg-red-50 border-red-200', text: 'text-red-700' },
        info: { icon: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>', bg: 'bg-blue-50 border-blue-200', text: 'text-blue-700' }
    };
    const typeStyle = iconMap[type] || iconMap['info'];
    const toast = document.createElement('div');
    toast.className = `fixed bottom-5 right-5 z-[100] px-4 py-3 rounded-xl shadow-lg text-sm font-medium flex items-center gap-3 border ${typeStyle.bg} ${typeStyle.text} animate-toast-in max-w-sm`;
    toast.innerHTML = `${typeStyle.icon} <span>${escapeHtml(message)}</span> <button onclick="this.parentElement.remove()" class="ml-auto -mr-1 p-1 text-current opacity-70 hover:opacity-100">×</button>`;
    toastContainer.appendChild(toast);
    const timer = setTimeout(() => {
        toast.classList.remove('animate-toast-in');
        toast.classList.add('animate-toast-out');
        toast.addEventListener('animationend', () => toast.remove());
    }, 2500); // Trigger fade-out after 2.5s (total visible time ~3s with 0.5s animation)
    toast.querySelector('button').addEventListener('click', () => clearTimeout(timer));
}

function createToastContainer() {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'fixed bottom-5 right-5 z-[100] space-y-2';
        document.body.appendChild(container);
    }
    return container;
}

function showSuccessToast(message) { showToast(message, 'success'); }
function showErrorToast(message) { showToast(message, 'error'); }

// --- Get CSRF Token ---
function getCsrfToken() {
    const tokenMeta = document.querySelector('meta[name="csrf-token"]');
    if (!tokenMeta) {
        console.error("CSRF meta tag not found!");
        return null;
    }
    return tokenMeta.getAttribute('content');
}

// --- Button Loading State ---
function setButtonLoading(button, isLoading) {
    if (!button) return;
    button.disabled = isLoading;
    const buttonTextSpan = button.querySelector('.button-text');
    const loadingSpan = button.querySelector('.loading');

    if (isLoading) {
        if (buttonTextSpan) buttonTextSpan.style.visibility = 'hidden'; // Hide text but keep space
        if (loadingSpan) loadingSpan.classList.remove('hidden');
    } else {
        if (buttonTextSpan) buttonTextSpan.style.visibility = 'visible';
        if (loadingSpan) loadingSpan.classList.add('hidden');
    }
}

// --- Improved handleResponse for Fetch ---
async function handleFetchResponse(response) {
    let data;
    const contentType = response.headers.get("content-type");

    if (!response.ok) {
        // Handle HTTP errors (like 500, 404, 403 etc.) first
        let errorMessage = `Server error ${response.status}: ${response.statusText}`;
        let errorBody = null; // To store parsed JSON error if available
        try {
            // Try to get more specific error text from the response body
            const errorText = await response.text();
            console.error("Server Error Response Body:", errorText); // Log the raw error body

            // Attempt to parse as JSON ONLY IF content type suggests it AND text is not empty
            if (errorText && contentType && contentType.includes("application/json")) {
                try {
                    errorBody = JSON.parse(errorText); // Use parse since await response.json() fails on non-ok
                    errorMessage = errorBody.message || errorMessage; // Use message from JSON if available
                } catch (jsonError) {
                    errorMessage = `Server error ${response.status}. Could not parse JSON error response. Body: ${errorText.substring(0, 200)}...`;
                }
            } else if (errorText) {
                // Non-JSON error response (e.g., HTML error page)
                 errorMessage = `Server error ${response.status}. Response: ${errorText.substring(0, 200)}...`;
            }
        } catch (textError) {
            errorMessage = `Server error ${response.status}: ${response.statusText}. Could not read error response body.`;
        }

        // Check specifically for our custom profile redirect signal (even on error)
        if (errorBody && errorBody.redirect && response.status === 403) {
            showErrorToast(errorBody.message || "Profile completion required.");
            setTimeout(() => { window.location.href = errorBody.redirect; }, 1500);
            const redirectError = new Error("Redirecting due to incomplete profile");
            redirectError.isRedirect = true;
            throw redirectError; // Signal handled redirect
        }

        const error = new Error(errorMessage);
        error.data = errorBody; // Attach parsed JSON error data if available
        error.status = response.status;
        throw error; // Throw the error to be caught by the caller
    }

    // --- Response.ok is true, proceed to parse body ---
    if (contentType && contentType.includes("application/json")) {
        try {
            data = await response.json(); // Now it's safe to assume response.ok

             // Check for business logic errors even with 200 OK (where success: false)
            if (data && data.success === false) {
                // It's a JSON response indicating failure, treat as error
                const error = new Error(data.message || `Request failed despite HTTP 200 OK.`);
                error.data = data;
                error.status = response.status; // 200
                 // Check for our custom redirect signal even on success:false
                 if (data.redirect && response.status === 403) { // Re-check status just in case
                    showErrorToast(data.message || "Profile completion required.");
                    setTimeout(() => { window.location.href = data.redirect; }, 1500);
                    const redirectError = new Error("Redirecting due to incomplete profile");
                    redirectError.isRedirect = true;
                    throw redirectError; // Signal handled redirect
                }
                throw error;
            }

        } catch (jsonError) {
            console.error("JSON Parsing Error on OK response:", jsonError);
            const errorText = await response.text().catch(() => "[Could not read response text]"); // Try reading text again
            const error = new Error(`Invalid JSON received from server (Status: ${response.status}). Body: ${errorText.substring(0,200)}...`);
            error.status = response.status;
            throw error;
        }
    } else {
        // Handle non-JSON success responses? Usually unexpected for an API.
        const text = await response.text();
        console.warn("Received non-JSON success response:", text);
        const error = new Error(`Unexpected non-JSON response from server (Status: ${response.status}).`);
        error.status = response.status;
        throw error;
    }

    return data; // Return parsed JSON data on actual success
}


// --- Registration Logic (Event Page Button) ---
async function initiateRegistration(event, eventId, eventPrice) {
    event.preventDefault();
    const button = event.currentTarget;

    if (isNaN(eventPrice)) {
        showErrorToast("Invalid event price data.");
        return;
    }

    // --- Check if already registered (using the button's state) ---
    // This prevents unnecessary calls if the button already says "Registered"
    if (button.dataset.registered === '1') {
        showToast("You are already registered for this event.", "info");
        // Optional: If you want the button to act as a cancel toggle, call cancel logic here.
        // handleCancelRegistration(event, eventId); // Example if toggling
        return; // Stop if already registered and not toggling
    }
    // --- End Check ---


    setButtonLoading(button, true);

    if (eventPrice > 0) {
        // Paid Event: Create ToyyibPay Bill
        await createToyyibpayBill(eventId, button);
    } else {
        // Free Event: Register directly
        await handleFreeRegistration(eventId, button);
    }
    // Loading state is reset within the specific functions, except on redirect
}

// --- Create ToyyibPay Bill (Called by initiateRegistration) ---
async function createToyyibpayBill(eventId, button) {
    const csrfToken = getCsrfToken();
    if (!csrfToken) {
        showErrorToast('Security token missing. Please refresh.');
        setButtonLoading(button, false);
        return;
    }

    const formData = new FormData();
    formData.append('event_id', eventId);
    formData.append('csrf_token', csrfToken);

    try {
        const response = await fetch('/actions/create_toyyibpay_bill.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        });

        const data = await handleFetchResponse(response); // Use improved handler

        if (data.success && data.paymentUrl) {
            showToast('Redirecting to payment gateway...', 'info');
            window.location.href = data.paymentUrl;
            // No need to reset loading state, page navigates away
        } else {
            // Error message handled by handleFetchResponse throwing an error
            // This block might not be reached if handleFetchResponse throws
            showErrorToast(data.message || 'Failed to initiate payment. Please try again.');
             setButtonLoading(button, false);
        }
    } catch (error) {

        if (error.isRedirect) {
            // Redirect is already handled by handleFetchResponse, just log maybe
            console.log("Redirecting to profile page...");
            // DON'T reset button loading state here because page is navigating away
            return; // Stop further execution
        }

        console.error('ToyyibPay initiation error:', error);
        // Show the message from the thrown error
        showErrorToast(error.message || 'An error occurred while contacting the payment gateway.');
        setButtonLoading(button, false);
    }
}

// --- Handle Free Registration (Called by initiateRegistration) ---
async function handleFreeRegistration(eventId, button) {
    const csrfToken = getCsrfToken();
    if (!csrfToken) {
        showErrorToast('Security token missing. Please refresh.');
        setButtonLoading(button, false);
        return;
    }

    try {
        const response = await fetch('/actions/register_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ event_id: eventId, csrf_token: csrfToken })
        });

        const data = await handleFetchResponse(response); // Use improved handler

        if (data.success) {
            showSuccessToast(data.message || 'Successfully registered!');
            updateRegistrationButtonUI(button, true); // Update UI to 'Registered' state

            // --- START: Dynamic update for Online Event Access section ---
            const onlineEventAccessSection = document.getElementById('onlineEventAccessSection');
            if (onlineEventAccessSection) { // Check if this event is online and section exists
                const eventLink = onlineEventAccessSection.dataset.eventLink;
                const eventVenue = onlineEventAccessSection.dataset.eventVenue;
                // Use the data attribute that reflects current link validity status.
                // This might be true even if eventLink is empty, if PHP determined it so.
                const isLinkValidInitially = onlineEventAccessSection.dataset.eventLinkValid === 'true';
                // For dynamic update, re-evaluate link validity based on fetched/updated eventLink
                const isLinkNowValid = isLinkValidInitially && eventLink && eventLink !== '#';


                const messageNotRegistered = document.getElementById('onlineEventMessageNotRegistered');
                const registeredContent = document.getElementById('onlineEventRegisteredContent');
                const linkAvailableDiv = document.getElementById('onlineEventLinkAvailable');
                const linkNotAvailableDiv = document.getElementById('onlineEventLinkNotAvailable');
                const joinLinkAnchor = document.getElementById('onlineEventJoinLink');
                const platformSpan = document.getElementById('onlineEventPlatform');

                if (messageNotRegistered) messageNotRegistered.classList.add('hidden');
                if (registeredContent) registeredContent.classList.remove('hidden');

                if (linkAvailableDiv && linkNotAvailableDiv && joinLinkAnchor && platformSpan) {
                    if (isLinkNowValid) { // Check if the link is actually valid and present
                        joinLinkAnchor.href = eventLink;
                        platformSpan.textContent = eventVenue || 'N/A'; // Ensure venue has a fallback
                        linkAvailableDiv.classList.remove('hidden');
                        linkNotAvailableDiv.classList.add('hidden');
                    } else {
                        linkAvailableDiv.classList.add('hidden');
                        linkNotAvailableDiv.classList.remove('hidden');
                    }
                }
            }
            // --- END: Dynamic update for Online Event Access section ---

        } else {
             // Error message handled by handleFetchResponse throwing an error
             // This block might not be reached if handleFetchResponse throws
            showErrorToast(data.message || 'Free registration failed.');
        }

    } catch (error) {

        if (error.isRedirect) {
            console.log("Redirecting to profile page...");
            // Might still want to reset button state if redirect *fails* for some reason,
            // but generally, page navigation handles it. Let's NOT reset here.
            return; // Stop further execution
        }

        console.error('Free registration error:', error);
         // Show the message from the thrown error
        showErrorToast(error.message || 'An error occurred during registration.');
    } finally {
        setButtonLoading(button, false); // Always restore button state
    }
}

document.addEventListener('click', function(e) {
    // Find the closest button ancestor that might be the target
    const button = e.target.closest('button[data-profile-incomplete="true"]');
    if (button && button.disabled) {
        // Find associated message or construct one
        const message = button.title || 'Please complete your profile first.';
        showToast(message + ' <a href="/pages/account_info.php?reason=incomplete_profile" class="underline font-bold ml-2">Update Profile</a>', 'info');
    }
});

// --- Handle Cancellation (Called by confirmCancellation or other cancel buttons) ---
async function handleCancelRegistrationAction(eventId, buttonToUpdate, cardToRemove = null) {
    // buttonToUpdate: The button whose state needs changing (e.g., main register button)
    // cardToRemove: The element to remove from UI (e.g., card on my_events page)

    const csrfToken = getCsrfToken();
    if (!csrfToken) {
        showErrorToast('Security token missing. Please refresh.');
        return false; // Indicate failure
    }

    showToast('Cancelling registration...', 'info');

    try {
        const response = await fetch('/actions/cancel_registration.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ event_id: eventId, csrf_token: csrfToken })
        });

        const data = await handleFetchResponse(response); // Use improved handler

        if (data.success) {
            showSuccessToast(data.message || 'Registration cancelled.');

            // Update the main button state if provided
            if (buttonToUpdate) {
                updateRegistrationButtonUI(buttonToUpdate, false); // Update to 'Register Now' state
            }

            // Remove the card if provided (e.g., from my_events)
            if (cardToRemove) {
                 cardToRemove.style.transition = 'opacity 0.3s ease, transform 0.3s ease, height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
                 cardToRemove.style.opacity = '0';
                 cardToRemove.style.transform = 'scale(0.95)';
                 cardToRemove.style.height = '0';
                 cardToRemove.style.margin = '0';
                 cardToRemove.style.padding = '0';
                 cardToRemove.style.overflow = 'hidden';
                 setTimeout(() => {
                    const parentList = cardToRemove.parentElement; // Get parent before removing
                    cardToRemove.remove();
                    updateEmptyState(parentList); // Check if list is empty now
                 }, 300);
            }
            return true; // Indicate success
        } else {
            // Error message handled by handleFetchResponse throwing an error
             // This block might not be reached if handleFetchResponse throws
            showErrorToast(data.message || 'Cancellation failed.');
            return false; // Indicate failure
        }

    } catch (error) {
        console.error('Cancellation error:', error);
         // Show the message from the thrown error
        showErrorToast(error.message || 'An error occurred during cancellation.');
        return false; // Indicate failure
    }
}

// --- Helper to Update Registration Button UI ---
function updateRegistrationButtonUI(button, isRegistered) {
    if (!button) return;

    button.dataset.registered = isRegistered ? '1' : '0';
    const buttonTextSpan = button.querySelector('.button-text');
    const iconElement = button.querySelector('i.fas');

    if (buttonTextSpan) buttonTextSpan.textContent = isRegistered ? 'Registered' : 'Register Now';

    if (iconElement) {
        iconElement.classList.remove(isRegistered ? 'fa-user-plus' : 'fa-check-circle');
        iconElement.classList.add(isRegistered ? 'fa-check-circle' : 'fa-user-plus');
    }

    // Update button styles
    button.classList.remove(...(isRegistered
        ? ['bg-purple-600', 'hover:bg-purple-700', 'focus:ring-purple-500']
        : ['bg-green-600', 'hover:bg-green-700', 'focus:ring-green-500']));
    button.classList.add(...(isRegistered
        ? ['bg-green-600', 'hover:bg-green-700', 'focus:ring-green-500']
        : ['bg-purple-600', 'hover:bg-purple-700', 'focus:ring-purple-500']));

    // Update the text below the button (common on event.php)
    const belowButtonText = button.nextElementSibling;
    if (belowButtonText && belowButtonText.tagName === 'P') {
        if (isRegistered) {
            belowButtonText.textContent = 'You are registered for this event.';
            belowButtonText.className = 'text-center text-sm text-green-700 mt-3 font-medium';
        } else {
            // When cancelled, maybe just clear the text or show a generic message
            belowButtonText.textContent = 'Registration cancelled.'; // Or maybe hide it: belowButtonText.textContent = '';
            belowButtonText.className = 'text-center text-sm text-gray-500 mt-3';
        }
    }
}


// --- Feedback Submission (Event Page) ---
const feedbackForm = document.getElementById('feedbackForm');
const feedbackCommentInput = document.getElementById('feedbackComment');
const submitFeedbackBtn = document.getElementById('submitFeedbackBtn');
const feedbackErrorDiv = document.getElementById('feedbackError');
const feedbackSection = document.getElementById('feedbackSection');

if (feedbackForm) {
    feedbackForm.addEventListener('submit', handleFeedbackSubmit);
}

async function handleFeedbackSubmit(event) {
    event.preventDefault();

    if (!feedbackCommentInput || !submitFeedbackBtn || !feedbackErrorDiv) {
        console.error('Feedback form elements missing!');
        showErrorToast('An error occurred with the feedback form.');
        return;
    }

    const comment = feedbackCommentInput.value.trim();
    const eventIdInput = feedbackForm.querySelector('input[name="event_id"]');
    const eventId = eventIdInput ? eventIdInput.value : null;
    const csrfToken = getCsrfToken();

    // Client-side Validation
    feedbackErrorDiv.textContent = '';
    feedbackErrorDiv.classList.add('hidden');
    let isValid = true;

    if (!comment) {
        feedbackErrorDiv.textContent = 'Feedback comment cannot be empty.'; isValid = false;
    } else if (comment.length > 1000) {
        feedbackErrorDiv.textContent = 'Feedback is too long (max 1000 characters).'; isValid = false;
    }
    if (!eventId) { feedbackErrorDiv.textContent = 'Event ID is missing.'; isValid = false; }
    if (!csrfToken) { feedbackErrorDiv.textContent = 'Security token missing. Please refresh.'; isValid = false; }

    if (!isValid) {
        feedbackErrorDiv.classList.remove('hidden');
        showErrorToast('Please correct the errors in the feedback form.');
        return;
    }

    setButtonLoading(submitFeedbackBtn, true);
    feedbackCommentInput.disabled = true;

    const formData = new FormData();
    formData.append('event_id', eventId);
    formData.append('comment', comment);
    formData.append('csrf_token', csrfToken);

    try {
        const response = await fetch('/actions/submit_feedback.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        });

        const result = await handleFetchResponse(response); // Use improved handler

        if (result.success) { // No need for response.ok check here, handleFetchResponse did it
            showSuccessToast(result.message || 'Feedback submitted successfully!');
            if (feedbackSection) {
                feedbackSection.innerHTML = `
                    <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded-md shadow-sm">
                        <div class="flex items-center">
                            <div class="flex-shrink-0"> <i class="fas fa-check-circle text-green-500 text-xl"></i> </div>
                            <div class="ml-3"> <p class="text-sm font-medium text-green-800"> ${escapeHtml(result.message || 'Thank you! Your feedback has been submitted.')} </p> </div>
                        </div>
                    </div>`;
            }
        } else {
             // Error already handled by handleFetchResponse throwing an error
             // This block might not be reached
            const errorMessage = result.message || 'Failed to submit feedback.';
            feedbackErrorDiv.textContent = errorMessage;
            feedbackErrorDiv.classList.remove('hidden');
            showErrorToast(errorMessage);
             // Re-enable form elements on explicit failure from backend
             setButtonLoading(submitFeedbackBtn, false);
             feedbackCommentInput.disabled = false;
        }

    } catch (error) {
        console.error('Feedback submission error:', error);
        const networkErrorMsg = error.message || 'A network error occurred.'; // Use error message from throw
        feedbackErrorDiv.textContent = networkErrorMsg;
        feedbackErrorDiv.classList.remove('hidden');
        showErrorToast(networkErrorMsg);
         // Re-enable form elements on catch
         setButtonLoading(submitFeedbackBtn, false);
         feedbackCommentInput.disabled = false;
    }
    // No finally needed as button state is reset on error cases above
}

// --- My Events Page Modal & Cancellation ---



// --- Confirmation Modal Logic (Used by My Events page) ---
function showConfirmation(eventId) {
    if (!eventId) return;
    pendingCancellationEventId = eventId; // Store the ID to be cancelled
    const modal = document.getElementById('confirmationModal');
    if (!modal) {
        console.error("Confirmation modal element not found!");
        showErrorToast("Cannot confirm cancellation: UI missing.");
        return;
    }
    const modalBox = modal.querySelector('div > div');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    modal.style.opacity = '0';
    setTimeout(() => {
        modal.style.opacity = '1';
        if (modalBox) modalBox.classList.add('animate-modal-in');
    }, 10);
    // Add keydown listener specific to this modal instance
    document.addEventListener('keydown', handleConfirmationModalKeys);
}

function hideConfirmation() {
    const modal = document.getElementById('confirmationModal');
    if (!modal || modal.classList.contains('hidden')) return;
    const modalBox = modal.querySelector('div > div');
    modal.style.opacity = '0';
    if (modalBox) modalBox.classList.remove('animate-modal-in');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        pendingCancellationEventId = null; // Clear ID only after hiding
    }, 300);
     // Remove the specific keydown listener
    document.removeEventListener('keydown', handleConfirmationModalKeys);
}
// Specific ESC handler for confirmation modal
function handleConfirmationModalKeys(e) {
     if (e.key === 'Escape') {
         hideConfirmation();
     }
 }


// confirmCancellation: Called when "Yes, Cancel" is clicked in confirmation modal
async function confirmCancellation() {
    if (!pendingCancellationEventId) return;

    const eventId = pendingCancellationEventId;
    const confirmationModal = document.getElementById('confirmationModal');
    const confirmButton = confirmationModal ? confirmationModal.querySelector('button[onclick="confirmCancellation()"]') : null;
    const eventCardToRemove = document.querySelector(`.event-card-myevents[data-event-id="${eventId}"]`); // Find card on my_events

    hideConfirmation(); // Hide modal immediately

    if (confirmButton) setButtonLoading(confirmButton, true);

    // Find the main registration button on the event page IF we are on that page
    let mainRegisterButton = null;
    if (document.body.contains(document.getElementById('mainRegisterButton'))) { // Check if element exists
        const potentialMainButton = document.getElementById('mainRegisterButton');
        if (potentialMainButton.dataset.eventId == eventId) { // Check if it's for the correct event
             mainRegisterButton = potentialMainButton;
        }
    }

    // Call the actual cancellation logic
    const success = await handleCancelRegistrationAction(eventId, mainRegisterButton, eventCardToRemove);

    // Restore confirmation button state (though it's hidden now)
    if (confirmButton) setButtonLoading(confirmButton, false);
}


// --- Helper to check and show/hide empty state message in lists (My Events page) ---
function updateEmptyState(listContainer) {
    if (!listContainer) return;
    // Use a more specific selector if cards have a common class like 'event-card-myevents'
    const items = listContainer.querySelectorAll('.event-card-myevents');
    const emptyState = listContainer.querySelector('.empty-state-message'); // Give your empty message div this class

    if (emptyState) {
        emptyState.style.display = items.length === 0 ? 'block' : 'none';
    }
}

// Add event listener for clicks outside modals (already includes confirmation)
document.addEventListener('click', (e) => {
    const confirmationModal = document.getElementById('confirmationModal');
    // Only handle the confirmation modal click outside here
    if (confirmationModal && e.target === confirmationModal) hideConfirmation();
});

document.addEventListener('DOMContentLoaded', function() {
    const registerButton = document.getElementById('mainRegisterButton');
    if (registerButton?.dataset.paymentPending === 'true') {
        const eventId = registerButton.dataset.eventId;
        const regId = registerButton.dataset.regId;
        
        // Start status check interval
        const statusCheckInterval = setInterval(() => {
            fetch(`/api/check-payment-status?event_id=${eventId}&reg_id=${regId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'pending') {
                        clearInterval(statusCheckInterval);
                        window.location.reload();
                    }
                })
                .catch(error => console.error('Status check failed:', error));
        }, 10000);
    }
});

// --- Follow/Unfollow Organizer ---
async function toggleFollowOrganizer(buttonElement) {
    const organizerId = buttonElement.getAttribute('data-organizer-id');
    const isCurrentlyFollowing = buttonElement.getAttribute('data-following') === 'true';
    const action = isCurrentlyFollowing ? 'unfollow' : 'follow';
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const errorElement = document.getElementById(`follow-error-${organizerId}`);

    if (!organizerId || !csrfToken) {
        console.error('Missing organizer ID or CSRF token for follow action.');
        if (errorElement) {
            errorElement.textContent = 'Error: Could not process request.';
            errorElement.classList.remove('hidden');
        }
        return;
    }

    // Hide previous error
    if (errorElement) errorElement.classList.add('hidden');

    // Set loading state
    setButtonLoading(buttonElement, true);

    try {
        const response = await fetch('/actions/handle_follow.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest' // Optional: Indicate AJAX
            },
            body: new URLSearchParams({
                organizer_id: organizerId,
                action: action,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();

        if (response.ok && result.success) {
            // Update button state based on the actual new state from backend
            const nowFollowing = result.newState === 'following';
            buttonElement.setAttribute('data-following', nowFollowing ? 'true' : 'false');

            // Update button appearance (Tailwind classes and icon/text)
            const buttonTextSpan = buttonElement.querySelector('.button-text');
            if (nowFollowing) {
                buttonElement.classList.remove('border-green-300', 'bg-green-50', 'text-green-700', 'hover:bg-green-100', 'focus:ring-green-500');
                buttonElement.classList.add('border-red-300', 'bg-red-50', 'text-red-700', 'hover:bg-red-100', 'focus:ring-red-500');
                if (buttonTextSpan) buttonTextSpan.innerHTML = '<i class="fas fa-user-minus"></i> Unfollow';
            } else {
                buttonElement.classList.remove('border-red-300', 'bg-red-50', 'text-red-700', 'hover:bg-red-100', 'focus:ring-red-500');
                buttonElement.classList.add('border-green-300', 'bg-green-50', 'text-green-700', 'hover:bg-green-100', 'focus:ring-green-500');
                if (buttonTextSpan) buttonTextSpan.innerHTML = '<i class="fas fa-user-plus"></i> Follow';
            }

            // Optional: Show success toast (if you have a toast system)
            // showToast(result.message || (nowFollowing ? 'Successfully followed!' : 'Successfully unfollowed!'), 'success');

        } else {
            // Show error message
            console.error('Follow/Unfollow failed:', result.message);
            if (errorElement) {
                 errorElement.textContent = result.message || 'An error occurred.';
                 errorElement.classList.remove('hidden');
             }
            // Optional: Show error toast
            // showToast(result.message || 'Action failed.', 'error');
        }

    } catch (error) {
        console.error('Error during fetch for follow/unfollow:', error);
         if (errorElement) {
             errorElement.textContent = 'Network error. Please try again.';
             errorElement.classList.remove('hidden');
         }
        // Optional: Show error toast
        // showToast('Network error. Please try again.', 'error');
    } finally {
        // Remove loading state
        setButtonLoading(buttonElement, false);
    }
}

// Add setButtonLoading function if it doesn't exist globally
if (typeof setButtonLoading === 'undefined') {
    function setButtonLoading(button, isLoading) {
        if (!button) return;
        const textSpan = button.querySelector('.button-text');
        const loadingSpan = button.querySelector('.loading');
        button.disabled = isLoading;
        if (isLoading) {
            if (textSpan) textSpan.style.visibility = 'hidden';
            if (loadingSpan) loadingSpan.classList.remove('hidden');
            button.style.cursor = 'wait';
        } else {
             if (textSpan) textSpan.style.visibility = 'visible';
             if (loadingSpan) loadingSpan.classList.add('hidden');
             button.style.cursor = 'pointer';
        }
    }
}

// --- Save/Unsave Event ---
// --- Save/Unsave Event (with finally block logging) ---
async function toggleSaveEvent(buttonElement) {
    const eventId = buttonElement.getAttribute('data-event-id');
    const isCurrentlySaved = buttonElement.getAttribute('data-saved') === 'true';
    const action = isCurrentlySaved ? 'unsave' : 'save';
    const csrfToken = getCsrfToken();

    if (!eventId || !csrfToken) {
        showErrorToast('Cannot save/unsave event: Missing required data.');
        return;
    }

    setButtonLoading(buttonElement, true);

    try {
        const formData = new FormData();
        formData.append('event_id', eventId);
        formData.append('action', action);
        formData.append('csrf_token', csrfToken);

        const response = await fetch('/actions/handle_save.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        });

        const result = await handleFetchResponse(response);
        const nowSaved = result.newState === 'saved';

        // Update button state
        buttonElement.setAttribute('data-saved', nowSaved ? 'true' : 'false');
        const iconElement = buttonElement.querySelector('svg'); // For icon styling
        const iconPath = buttonElement.querySelector('svg path'); // For Font Awesome class swap if used differently
        const textSpan = buttonElement.querySelector('.button-text span'); // Assuming text is in a span inside .button-text

        if (iconElement) {
            // Style for SVG directly (like in search_results.php and index.php card)
            iconElement.setAttribute('fill', nowSaved ? 'currentColor' : 'none');
            iconElement.classList.toggle('text-purple-600', nowSaved); // Saved state color
            iconElement.classList.toggle('text-gray-400', !nowSaved);  // Unsaved state color
        }

        // For Font Awesome icons if used directly (like in event.php sidebar button)
        const faIconElement = buttonElement.querySelector('i.fa-fw');
        if (faIconElement) {
            faIconElement.classList.toggle('fas', nowSaved); // Solid bookmark if saved
            faIconElement.classList.toggle('far', !nowSaved); // Regular bookmark if not saved
            faIconElement.classList.toggle('text-red-500', nowSaved); // Color for saved state (event.php example)
            faIconElement.classList.toggle('text-gray-400', !nowSaved); // Color for unsaved (event.php example)
            // If your design uses group-hover for unsaved state, ensure that's also handled or consistent
            // faIconElement.classList.toggle('group-hover:text-indigo-500', !nowSaved); // Example if needed
        }

        // Update button text content
        if (textSpan) {
            textSpan.textContent = nowSaved ? 'Event Saved' : 'Save Event';
        } else {
            // Fallback if no specific .button-text span is found, try to update first child node if it's text
            // This is less precise and might need adjustment based on exact button HTML structure in all places.
            // For pages like event.php where the text is directly inside .button-text
            const directTextContainer = buttonElement.querySelector('.button-text');
            if (directTextContainer && directTextContainer.childNodes.length > 0) {
                for (let i = 0; i < directTextContainer.childNodes.length; i++) {
                    if (directTextContainer.childNodes[i].nodeType === Node.TEXT_NODE && directTextContainer.childNodes[i].textContent.trim() !== '') {
                        directTextContainer.childNodes[i].textContent = ` ${nowSaved ? 'Event Saved' : 'Save Event'}`; // Add space for icon
                        break;
                    } else if(directTextContainer.childNodes[i].nodeType === Node.ELEMENT_NODE && directTextContainer.childNodes[i].tagName === 'SPAN'){
                        directTextContainer.childNodes[i].textContent = nowSaved ? 'Event Saved' : 'Save Event';
                        break;
                    }
                }
            }
        }


        buttonElement.title = nowSaved ? 'Unsave Event' : 'Save Event';
        showSuccessToast(result.message || (nowSaved ? 'Event saved!' : 'Event unsaved!'));

    } catch (error) {
        console.error('Save/Unsave error:', error);
        showErrorToast(error.message || 'Action failed. Please try again.');
    } finally {
        setButtonLoading(buttonElement, false);
    }
}
// --- REMOVED Redundant/Incorrect handleRegistration function ---
// The logic is now correctly handled by initiateRegistration, handleFreeRegistration,
// handleCancelRegistrationAction, and confirmCancellation.