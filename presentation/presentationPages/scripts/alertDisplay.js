// Process Alerts Function (Handles Different Alerts)
function processAlerts(dynamicData, monitorType, streamCount) {
    // Assign Current Unit Time
	const currentUnixTime = dynamicData.timeDateArray.currentUnixTime;
	// Console Log (Stream Counts / Monitor Type)
    console.log('Stream Counts:', streamCount, 'Monitor Type:', monitorType);
    // Test Conditional (Commented Out)
	if (streamCount === 5 && monitorType === 0 && (1 == 2)) {
	//if (streamCount === 5 && monitorType === 0) {
        //
		triggerAlert("Test Alert", "This is a test alert after 5 counts.", 'celebration', 10);
    }
    // Current Interval Notice (every hour) (Under Construction !!)
    if (currentUnixTime % 3600 === 0 && monitorType === 0) {
        //
		triggerAlert("Current Interval Notice", "Time remaining in the current interval.", 'interval-notice', 10);
    }
    // Assign Latest Action
    const latestAction = dynamicData.updatableDatabaseArray.actionsArray.at(-1); // Using .at(-1) to get the last item
    // Latest Action Conditional
	if (latestAction && 
		// Action Types (Login / Logout)
		(latestAction.actionTypeID === "1" || latestAction.actionTypeID === "2") && 
		// Action ID / Action ID Trigger
		latestAction.actionID !== lastActionIDTriggered) {
		// Assign Contact ID
		const contactID = latestAction.actionContactID; // Assuming this is the contactID from the latestAction
		// Assign Contact Details
		const contactDetails = dynamicData.updatableDatabaseArray.contactsArray.find(
			// Contact
			(contact) => contact.contactID === contactID
		);
		// Assign Action Type
		const actionType = dynamicData.staticDatabaseArray.actionTypesArray.find(
			// Type
			(type) => type.actionTypeID === latestAction.actionTypeID
		);
		// Assign User Details
		const userDetails = contactDetails
			? `${contactDetails.contactFirstName} ${contactDetails.contactLastName} (${contactDetails.contactTitle})`
			: '';
		// Assign Action Name
		const actionName = actionType ? actionType.actionName : "unknown action"; // If the actionType is found, use its actionName; otherwise, fallback to "Unknown Action"
		// Assign Login Details
		const loginDetails = `
			<!-- Action ID -->
			<div style="margin: 2px 0; line-height: 1.2;"><strong>Action #:</strong> ACT-${latestAction.actionID.toString().padStart(5, '0')}</div>
			<!-- User -->
			<div style="margin: 2px 0; line-height: 1.2;"><strong>User:</strong> ${userDetails}</div>
			<!-- Action Time -->
			<div style="margin: 2px 0; line-height: 1.2;"><strong>Action Time:</strong> ${new Date(latestAction.actionTimestamp * 1000).toLocaleString()}</div>
		`;
		// Assign Alert Message
		const alertMessage = `A new <span style="color: red;">${actionName.toLowerCase()}</span> has occurred!`;
		// Assign Trigger Alert
		triggerAlert(
			"Breaking News", // Static header text
			alertMessage, // Alert message with HTML formatting
			"breaking-news", // Alert type
			10, // Duration
			loginDetails // Details content
		);
        // Assign Last Action ID Triggered
		lastActionIDTriggered = latestAction.actionID;
    }
    // Current Interval Notice (every 30 minutes) (Under Construction !!)
    if (currentUnixTime % 1800 === 0) {
        //
		//triggerAlert("Celebration", "It's a special day! Celebrate!", 'celebration', 10);
    }
}
// Trigger Alert Function
function triggerAlert(header, message, alertType, durationCount, details = '') {
    // Set Alert Header
	const alertHeader = document.getElementById('alert-header');
    // Set Alert Message
	const alertMessage = document.getElementById('alert-message');
    // Set Alert Details
	const alertDetails = document.getElementById('alert-details');
    // Set Alert Container
	const alertContainer = document.getElementById('mdlAlertPage');
    // Assign Alert Header (Inner HTML)
    alertHeader.innerHTML = `
        <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; margin: 0;">
            <div style="flex: 0 0 auto; padding-right: 10px;">
                <img src="../images/logos/christyLogo.png" alt="Logo" style="height: 40px;" />
            </div>
            <div style="flex: 1; text-align: left;">
                <div style="font-weight: bold; font-size: 1.2em;">${header}</div>
                <div style="width: 100%; height: 2px; background-color: red; margin: 5px 0;"></div>
                <div style="font-size: 0.95em; color: #333;">${message}</div>
            </div>
        </div>
    `;
	// Set Alert Message
    alertMessage.innerHTML = ''; // No direct content here as it's moved into the header
    // Set Alert Details
	alertDetails.innerHTML = details; // Add additional details if needed
	// Set Alert Container (Remove)
    alertContainer.classList.remove('breaking-news-effect', 'interval-notice-effect', 'celebration-effect');
    // Alert Type Conditional
    if (alertType === 'breaking-news') {
        // Set Alert Container (Breaking News Effect)
		alertContainer.classList.add('breaking-news-effect');
        // Call Play Sound (Breaking News Sound)
		playSound('breaking-news-sound');
    } else if (alertType === 'interval-notice') {
        // Set Alert Container (Interval Notice Effect)
		alertContainer.classList.add('interval-notice-effect');
        // Call Play Sound (Interval Notice Sound)
		playSound('interval-notice-sound');
    } else if (alertType === 'celebration') {
        // Set Alert Container (Celebration Effect)
		alertContainer.classList.add('celebration-effect');
        // Call Play Sound (Celebration Sound)
		playSound('celebration-sound');
        // Console Log (Celebration Triggered)
        console.log("Celebration triggered. Starting confetti...");
        // Start Confetti Conditional
		if (typeof startConfetti === 'function') {
            // Call Start Confetti Function
			startConfetti(); // Start confetti animation
        } else {
            // Console Error (Start Confetti)
			console.error("startConfetti function is not defined or not working properly.");
        }
    }
    // Set Modal Alert Page (Show)
    $('#mdlAlertPage').modal('show'); // Show the modal
    // Set Timeout (10 sec)
    setTimeout(function () {
        // Call Stop Alert Effects
		stopAlertEffects();
    }, durationCount * 1000);
}
// Stop Alert Effects Function
function stopAlertEffects() {
    // Call Stop All Sounds Function
	stopAllSounds();
    // Call Stop Confetti Function
	stopConfetti(); // Ensure confetti stops before hiding the modal
    // Set Alert Page (Hide)
	$('#mdlAlertPage').modal('hide');
}
// Stop All Sounds Function
function stopAllSounds() {
    // Assign Sounds
	const sounds = ['interval-notice-sound', 'breaking-news-sound', 'celebration-sound'];
    // For Each Sounds
	sounds.forEach(soundId => {
        // Assign Sound Elements
		const soundElement = document.getElementById(soundId);
        // Sound Elements Conditional
		if (soundElement) {
            // Sound Element Pause
			soundElement.pause();
            // Sound Element Cuurent Time
			soundElement.currentTime = 0;
        }
    });
}
// Start Confetti Function
function startConfetti() {
    // Get the Confetti Canvas Element
    confettiCanvas = document.getElementById('confetti-canvas');
    // Check if the Canvas is Found
    if (!confettiCanvas) {
        // Log an Error Message if Canvas is Missing
        console.error('Confetti canvas not found.');
        // Exit Function
        return;
    }
    // Get the 2D Context of the Canvas
    confettiCtx = confettiCanvas.getContext('2d');
    // Set the Canvas Width to Match the Window Width
    confettiCanvas.width = window.innerWidth;
    // Set the Canvas Height to Match the Window Height
    confettiCanvas.height = window.innerHeight;
    // Initialize Confetti Particles Array
    confettiParticles = Array.from({ length: 100 }, () => ({
        // Set Random X Coordinate Within Canvas Width
        x: Math.random() * confettiCanvas.width,
        // Set Random Y Coordinate Within Canvas Height
        y: Math.random() * confettiCanvas.height,
        // Set Random Radius for Each Particle
        r: Math.random() * 10 + 2,
        // Set Random Color Using HSL Format
        color: `hsl(${Math.random() * 360}, 100%, 50%)`,
        // Set Random Horizontal Speed
        dx: Math.random() * 2 - 1,
        // Set Random Vertical Speed
        dy: Math.random() * 2 + 2,
        // Start Each Particle with Full Opacity
        opacity: 1,
    }));
    // Set Confetti Active to True
    confettiActive = true;
    // Start the Animation Loop for Confetti
    animateConfetti();
    // Stop the Confetti After 5 Seconds and Begin Fade-Out
    setTimeout(() => stopConfetti(), 5000);
}
// Animate Confetti Function
function animateConfetti() {
    // Exit Function if Confetti is No Longer Active
    if (!confettiActive) return;
    // Clear the Entire Canvas Before Redrawing Particles
    confettiCtx.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height);
    // Iterate Over Each Confetti Particle
    confettiParticles.forEach((particle) => {
        // Begin Drawing a Particle Path
        confettiCtx.beginPath();
        // Draw a Circular Particle Using Arc
        confettiCtx.arc(particle.x, particle.y, particle.r, 0, Math.PI * 2);
        // Set the Fill Color of the Particle
        confettiCtx.fillStyle = particle.color;
        // Apply Opacity to the Particle
        confettiCtx.globalAlpha = particle.opacity;
        // Fill the Particle Path
        confettiCtx.fill();
        // Update the Particle's Horizontal Position
        particle.x += particle.dx;
        // Update the Particle's Vertical Position
        particle.y += particle.dy;
        // Reset the Y Position if the Particle Exits the Bottom of the Canvas
        if (particle.y > confettiCanvas.height) particle.y = -particle.r;
        // Reset the X Position if the Particle Exits the Canvas Horizontally
        if (particle.x > confettiCanvas.width || particle.x < 0) particle.x = Math.random() * confettiCanvas.width;
    });
    // Request the Next Frame to Continue the Animation
    requestAnimationFrame(animateConfetti);
}
// Stop Confetti Function
function stopConfetti() {
    // Set Fade-Out Interval for Gradual Particle Fade-Out
    fadeOutInterval = setInterval(() => {
        // Initialize Active Particles Counter
        let activeParticles = 0;
        // Iterate Over Each Confetti Particle
        confettiParticles.forEach((particle) => {
            // Check If Particle is Still Visible (Opacity > 0)
            if (particle.opacity > 0) {
                // Reduce Particle Opacity Gradually
                particle.opacity -= 0.02; // Decrease opacity by 0.02 per interval
                // Ensure Particle Opacity Does Not Go Below Zero
                if (particle.opacity < 0) particle.opacity = 0;
                // Increment Active Particles Counter
                activeParticles++;
            }
        });
        // Redraw the Canvas During Fade-Out
        if (confettiCtx && confettiCanvas) {
            // Clear Canvas
            confettiCtx.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height);
            // Redraw Each Particle With Updated Opacity
            confettiParticles.forEach((particle) => {
                // Begin Drawing Path for Particle
                confettiCtx.beginPath();
                // Draw Circular Particle Shape
                confettiCtx.arc(particle.x, particle.y, particle.r, 0, Math.PI * 2);
                // Set Particle Fill Color
                confettiCtx.fillStyle = particle.color;
                // Apply Particle Opacity Using Global Alpha
                confettiCtx.globalAlpha = particle.opacity;
                // Fill the Particle on the Canvas
                confettiCtx.fill();
            });
        } else {
            // Console Warning If Canvas or Context is Not Available
            console.warn("Confetti canvas or context is not initialized.");
        }
        // Stop Fade-Out Process When All Particles Become Invisible
        if (activeParticles === 0) {
            // Clear the Fade-Out Interval
            clearInterval(fadeOutInterval);
            // Clear the Canvas Completely
            if (confettiCtx && confettiCanvas) {
                // Clear Rect
				confettiCtx.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height);
            }
            // Console Log Indicating Confetti Stopped
            console.log("Confetti animation has stopped.");
        }
    }, 50); // Set Interval Speed for Smooth Fade-Out (50ms)
}
// Play Sound Function
function playSound(eventType) {
	// Set Audio Element
	const audioElement  = document.getElementById('dynamic-audio');
	// Audio Element Conditional
	if (!audioElement) {
		// Console Error (Audio Element Not Found)
		console.error('Audio element not found.');
		// Return
		return;
	}
	// Switch Conditional
	switch (eventType) {
		// Breaking News
		case 'breaking-news-sound':
			// Audio
			audioElement.src = 'https://azsignpermits.com/audio/breakingNewsAudio.wav'; // URL for breaking news sound
			// Break
			break;
		// Celebration
		case 'celebration-sound':
			// Audio
			audioElement.src = 'https://azsignpermits.com/audio/boom.mp3'; // URL for celebration sound
			// Break
			break;
		// Current Interval Alert
		case 'interval-notice-sound':
			// Audio
			audioElement.src = 'https://azsignpermits.com/audio/successAudio.wav'; // URL for interval alerts
			// Break
			break;
		// Random Section Display
		case 'randomSectionDisplay':
			// Audio
			audioElement.src = 'https://azsignpermits.com/audio/readButtonAudio.wav'; // URL for section shuffle
			// Break
			break;
		// Announcement Update
		case 'announcementUpdate':
			// Audio
			audioElement.src = 'https://azsignpermits.com/audio/announcement.mp3'; // URL for company announcements
			// Break
			break;
		// Default
		default:
			// Console Log (No Sound)
			console.log('No sound for this event type');
			// Break
			return;
	}
	// Play Audio Element
	audioElement.play(); // Play the selected sound
	// Console Log (Event Type Played)
	console.log(eventType + ' played');
}