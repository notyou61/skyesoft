// controlDisplay.js

// WebSocket connection setup
const socket = new WebSocket("ws://localhost:8080"); // Replace with your WebSocket server URL

socket.onopen = () => {
    console.log("WebSocket connection established.");
};

/**
 * Show a specific slide by its ID or data-slide value.
 * @param {string} slideId - The ID or data-slide value of the target slide.
 */
function showSlide(slideId) {
    // Hide all slides
    document.querySelectorAll(".info-box").forEach(slide => {
        slide.classList.remove("active-card");
    });

    // Find the target slide and show it
    const targetSlide = document.querySelector(`[data-slide="${slideId}"], #${slideId}`);
    if (targetSlide) {
        targetSlide.classList.add("active-card");
        console.log(`Slide changed to: ${slideId}`);
    } else {
        console.error(`Slide with ID or data-slide '${slideId}' not found.`);
    }
}

// WebSocket message handling
socket.onmessage = (event) => {
    const command = JSON.parse(event.data);
    if (command.action === "changeSlide") {
        showSlide(command.slideId);
    }
};

socket.onerror = (error) => {
    console.error("WebSocket error:", error);
};

socket.onclose = () => {
    console.log("WebSocket connection closed.");
};