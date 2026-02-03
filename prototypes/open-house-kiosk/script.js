/**
 * Open House Kiosk Sign-In - JavaScript
 * Handles slideshow, modal, form steps, and interactions
 */

// ============================================
// State Management
// ============================================

const state = {
    currentStep: 1,
    agentStatus: null, // 'no', 'yes', 'this-agent'
    buyingTimeline: null,
    preApproved: null,
    formData: {}
};

// ============================================
// Slideshow
// ============================================

let currentSlide = 0;
const slides = document.querySelectorAll('.slide');
const indicators = document.querySelectorAll('.indicator');
let slideshowInterval;

function initSlideshow() {
    if (slides.length === 0) return;

    slideshowInterval = setInterval(nextSlide, 5000);

    // Click handlers for indicators
    indicators.forEach((indicator, index) => {
        indicator.addEventListener('click', () => goToSlide(index));
    });
}

function nextSlide() {
    goToSlide((currentSlide + 1) % slides.length);
}

function goToSlide(index) {
    slides[currentSlide].classList.remove('active');
    indicators[currentSlide].classList.remove('active');

    currentSlide = index;

    slides[currentSlide].classList.add('active');
    indicators[currentSlide].classList.add('active');

    // Reset interval
    clearInterval(slideshowInterval);
    slideshowInterval = setInterval(nextSlide, 5000);
}

// ============================================
// Modal Management
// ============================================

const modal = document.getElementById('signInModal');

function openSignIn() {
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Reset form state
    resetForm();
}

function closeSignIn() {
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

function resetForm() {
    state.currentStep = 1;
    state.agentStatus = null;
    state.buyingTimeline = null;
    state.preApproved = null;
    state.formData = {};

    // Reset all inputs
    document.querySelectorAll('.form-input').forEach(input => {
        input.value = '';
    });

    // Reset checkboxes
    document.querySelectorAll('.consent-checkbox').forEach(checkbox => {
        checkbox.checked = checkbox.id === 'consentFollowUp' || checkbox.id === 'consentEmail';
    });

    // Reset selected states
    document.querySelectorAll('.option-card.selected, .timeline-btn.selected, .preapproval-btn.selected').forEach(el => {
        el.classList.remove('selected');
    });

    // Show first step
    showStep(1);
    updateProgressSteps();
}

// ============================================
// Form Navigation
// ============================================

function showStep(stepNumber) {
    // Hide all steps
    document.querySelectorAll('.form-step').forEach(step => {
        step.classList.remove('active');
    });

    // Show target step
    const targetStep = document.querySelector(`.form-step[data-step="${stepNumber}"]`);
    if (targetStep) {
        targetStep.classList.add('active');
    }

    state.currentStep = stepNumber;
    updateProgressSteps();
}

function updateProgressSteps() {
    const steps = document.querySelectorAll('.progress-steps .step');
    const stepNumber = typeof state.currentStep === 'string' ?
        parseInt(state.currentStep.replace(/[ab]/, '')) : state.currentStep;

    steps.forEach((step, index) => {
        const num = index + 1;
        step.classList.remove('active', 'completed');

        if (num < stepNumber) {
            step.classList.add('completed');
        } else if (num === stepNumber) {
            step.classList.add('active');
        }
    });
}

function nextStep(stepNumber) {
    // Validate current step before proceeding
    if (!validateCurrentStep()) {
        return;
    }

    showStep(stepNumber);
}

function prevStep(stepNumber) {
    showStep(stepNumber);
}

function validateCurrentStep() {
    const currentStepEl = document.querySelector('.form-step.active');
    const stepNum = currentStepEl.dataset.step;

    if (stepNum === '1') {
        // Validate contact info
        const firstName = document.getElementById('firstName').value.trim();
        const lastName = document.getElementById('lastName').value.trim();
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone').value.trim();

        if (!firstName || !lastName || !email || !phone) {
            highlightEmptyFields(['firstName', 'lastName', 'email', 'phone']);
            return false;
        }

        if (!isValidEmail(email)) {
            document.getElementById('email').classList.add('error');
            return false;
        }

        state.formData = { firstName, lastName, email, phone };
    }

    return true;
}

function highlightEmptyFields(fieldIds) {
    fieldIds.forEach(id => {
        const field = document.getElementById(id);
        if (field && !field.value.trim()) {
            field.classList.add('error');
            setTimeout(() => field.classList.remove('error'), 2000);
        }
    });
}

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

// ============================================
// Agent Status Selection (Step 2)
// ============================================

function selectAgentStatus(status) {
    state.agentStatus = status;

    // Update UI
    document.querySelectorAll('.option-card').forEach(card => {
        card.classList.remove('selected');
    });
    event.currentTarget.classList.add('selected');

    // Navigate to appropriate step after a brief delay
    setTimeout(() => {
        if (status === 'no') {
            showStep('3b'); // Buying intent
        } else if (status === 'yes') {
            showStep('3a'); // Agent details
        } else if (status === 'this-agent') {
            showStep(4); // Skip to consent
        }
    }, 300);
}

// ============================================
// Timeline & Pre-approval Selection (Step 3b)
// ============================================

document.querySelectorAll('.timeline-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.timeline-btn').forEach(b => b.classList.remove('selected'));
        this.classList.add('selected');
        state.buyingTimeline = this.dataset.value;
    });
});

document.querySelectorAll('.preapproval-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.preapproval-btn').forEach(b => b.classList.remove('selected'));
        this.classList.add('selected');
        state.preApproved = this.dataset.value;

        // Show lender field if pre-approved
        const lenderGroup = document.querySelector('.lender-group');
        if (lenderGroup) {
            lenderGroup.style.display = this.dataset.value === 'yes' ? 'block' : 'none';
        }
    });
});

// ============================================
// Form Submission
// ============================================

function submitForm() {
    // Validate disclosure acknowledgment
    const maDisclosure = document.getElementById('maDisclosure');
    if (!maDisclosure.checked) {
        maDisclosure.parentElement.classList.add('error');
        setTimeout(() => maDisclosure.parentElement.classList.remove('error'), 2000);
        return;
    }

    // Collect all form data
    const formData = {
        ...state.formData,
        agentStatus: state.agentStatus,
        buyingTimeline: state.buyingTimeline,
        preApproved: state.preApproved,
        lenderName: document.getElementById('lenderName')?.value || null,
        otherAgentName: document.getElementById('agentName')?.value || null,
        otherAgentBrokerage: document.getElementById('agentBrokerage')?.value || null,
        otherAgentPhone: document.getElementById('agentPhone')?.value || null,
        otherAgentEmail: document.getElementById('agentEmail')?.value || null,
        consentFollowUp: document.getElementById('consentFollowUp').checked,
        consentEmail: document.getElementById('consentEmail').checked,
        consentText: document.getElementById('consentText').checked,
        maDisclosureAcknowledged: true
    };

    console.log('Form submitted:', formData);

    // Show alert detail if email consent given
    if (formData.consentEmail && formData.agentStatus === 'no') {
        document.getElementById('alertDetail').style.display = 'flex';
    }

    // Show success step
    showStep(5);

    // In production, send to API:
    // await submitToAPI(formData);
}

// ============================================
// Input Styling
// ============================================

document.querySelectorAll('.form-input').forEach(input => {
    input.addEventListener('focus', function() {
        this.classList.remove('error');
    });

    input.addEventListener('blur', function() {
        if (this.type === 'email' && this.value && !isValidEmail(this.value)) {
            this.classList.add('error');
        }
    });
});

// Add error styling
const style = document.createElement('style');
style.textContent = `
    .form-input.error {
        border-color: #ef4444 !important;
        animation: shake 0.4s ease;
    }

    .consent-item.error .consent-checkmark {
        border-color: #ef4444 !important;
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20%, 60% { transform: translateX(-4px); }
        40%, 80% { transform: translateX(4px); }
    }
`;
document.head.appendChild(style);

// ============================================
// Keyboard Navigation
// ============================================

document.addEventListener('keydown', (e) => {
    if (!modal.classList.contains('active')) return;

    if (e.key === 'Escape') {
        closeSignIn();
    }

    if (e.key === 'Enter' && !e.shiftKey) {
        const activeStep = document.querySelector('.form-step.active');
        const stepNum = activeStep?.dataset.step;

        // Don't auto-submit if in a textarea or on step 2 (option selection)
        if (stepNum === '2' || e.target.tagName === 'TEXTAREA') return;

        const nextBtn = activeStep?.querySelector('.btn-primary');
        if (nextBtn) {
            nextBtn.click();
        }
    }
});

// ============================================
// Touch Optimization for iPad
// ============================================

function optimizeForTouch() {
    // Prevent double-tap zoom on buttons
    document.querySelectorAll('button, .option-card').forEach(el => {
        el.addEventListener('touchend', (e) => {
            e.preventDefault();
            el.click();
        }, { passive: false });
    });

    // Smooth scrolling in modal
    const formContent = document.querySelector('.form-content');
    if (formContent) {
        formContent.style.webkitOverflowScrolling = 'touch';
    }
}

// ============================================
// Initialize
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    initSlideshow();
    optimizeForTouch();

    // Prevent zoom on input focus (iOS)
    document.querySelectorAll('input, select, textarea').forEach(el => {
        el.setAttribute('autocomplete', 'off');
        el.setAttribute('autocorrect', 'off');
        el.setAttribute('autocapitalize', 'off');
    });
});

// ============================================
// API Integration (Production)
// ============================================

async function submitToAPI(formData) {
    try {
        const response = await fetch('/wp-json/mld-mobile/v1/open-houses/1/attendees', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getAuthToken()}`
            },
            body: JSON.stringify({
                first_name: formData.firstName,
                last_name: formData.lastName,
                email: formData.email,
                phone: formData.phone,
                is_agent: false,
                working_with_agent: formData.agentStatus === 'yes' ? 'yes_other' :
                                   formData.agentStatus === 'this-agent' ? 'yes_this_agent' : 'no',
                other_agent_name: formData.otherAgentName,
                other_agent_brokerage: formData.otherAgentBrokerage,
                other_agent_phone: formData.otherAgentPhone,
                other_agent_email: formData.otherAgentEmail,
                buying_timeline: formData.buyingTimeline,
                pre_approved: formData.preApproved,
                lender_name: formData.lenderName,
                consent_to_follow_up: formData.consentFollowUp,
                consent_to_email: formData.consentEmail,
                consent_to_text: formData.consentText,
                ma_disclosure_acknowledged: formData.maDisclosureAcknowledged
            })
        });

        if (!response.ok) {
            throw new Error('API request failed');
        }

        const result = await response.json();
        console.log('Attendee created:', result);
        return result;
    } catch (error) {
        console.error('Error submitting form:', error);
        // Show error to user
        alert('There was an error saving your information. Please try again.');
    }
}

function getAuthToken() {
    // In production, this would come from the iOS app's token manager
    return window.authToken || '';
}
