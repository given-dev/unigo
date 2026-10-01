// Initialize Leaflet Map
let map;
let markers = [];

function initMap() {
    // Default location: Kampala, Uganda
    map = L.map('transit-map').setView([0.3476, 32.5825], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
}

function addMarker(lat, lng, popup) {
    const marker = L.marker([lat, lng]).addTo(map).bindPopup(popup);
    markers.push(marker);
    return marker;
}

function clearMarkers() {
    markers.forEach(marker => map.removeLayer(marker));
    markers = [];
}

// Geolocation
function getCurrentLocation() {
    return new Promise((resolve, reject) => {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                position => resolve({
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                }),
                error => reject(error)
            );
        } else {
            reject(new Error('Geolocation not supported'));
        }
    });
}

// Ride Matching API
async function searchRides(origin, destination, date) {
    try {
        const response = await fetch('php/api/search_rides.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ origin, destination, date })
        });
        return await response.json();
    } catch (error) {
        console.error('Error searching rides:', error);
        return [];
    }
}

async function bookRide(rideId, userId) {
    try {
        const response = await fetch('php/api/book_ride.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ride_id: rideId, user_id: userId })
        });
        return await response.json();
    } catch (error) {
        console.error('Error booking ride:', error);
        throw error;
    }
}

// Vehicle Tracking
async function getVehicleLocations() {
    try {
        const response = await fetch('php/api/get_vehicles.php');
        return await response.json();
    } catch (error) {
        console.error('Error fetching vehicles:', error);
        return [];
    }
}

function updateVehicleMarkers(vehicles) {
    clearMarkers();
    vehicles.forEach(vehicle => {
        const popup = `<b>${vehicle.type}</b><br>Route: ${vehicle.route}<br>Status: ${vehicle.status}`;
        addMarker(vehicle.lat, vehicle.lng, popup);
    });
}

// Real-time updates
function startTracking() {
    setInterval(async () => {
        const vehicles = await getVehicleLocations();
        updateVehicleMarkers(vehicles);
    }, 5000); // Update every 5 seconds
}

// Form handlers
document.addEventListener('DOMContentLoaded', () => {
    // Initialize map if element exists
    if (document.getElementById('transit-map')) {
        initMap();
        startTracking();
    }

    // Login form
    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(loginForm);
            try {
                const response = await fetch('php/api/login.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    window.location.href = 'dashboard.php';
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('Login failed');
            }
        });
    }

    // Register form
    const registerForm = document.getElementById('register-form');
    if (registerForm) {
        registerForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(registerForm);
            try {
                const response = await fetch('php/api/register.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    alert('Registration successful! Please login.');
                    window.location.href = 'login.php';
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('Registration failed');
            }
        });
    }

    // Ride search form
    const rideSearchForm = document.getElementById('ride-search-form');
    if (rideSearchForm) {
        rideSearchForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const origin = document.getElementById('origin').value;
            const destination = document.getElementById('destination').value;
            const date = document.getElementById('date').value;
            
            const rides = await searchRides(origin, destination, date);
            displayRideResults(rides);
        });
    }
});

function displayRideResults(rides) {
    const resultsContainer = document.getElementById('ride-results');
    if (!resultsContainer) return;
    
    resultsContainer.innerHTML = rides.length === 0 
        ? '<p>No rides found</p>'
        : rides.map(ride => `
            <div class="match-card">
                <div>
                    <strong>${ride.driver_name}</strong>
                    <p>${ride.origin} → ${ride.destination}</p>
                    <p>Date: ${ride.date} | Time: ${ride.time}</p>
                    <p>Available seats: ${ride.seats_available}</p>
                </div>
                <button class="btn btn-primary" onclick="bookRide(${ride.id}, ${ride.user_id})">
                    Book Seat
                </button>
            </div>
        `).join('');
}
