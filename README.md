# UniGo - Integrated Smart Commute & Transit System

An Integrated Smart Commute & Transit System that uses modern technology to improve transportation in Uganda.

## Project Structure

```
UniGo/
├── css/
│   └── style.css          # Main stylesheet
├── js/
│   └── main.js            # Frontend JavaScript (map, API calls)
├── php/
│   ├── config/
│   │   └── database.php   # Database configuration
│   ├── api/
│   │   ├── register.php   # User registration
│   │   ├── login.php      # User login
│   │   ├── search_rides.php   # Search available rides
│   │   ├── book_ride.php      # Book a ride
│   │   └── get_vehicles.php   # Get vehicle locations
│   └── includes/
│       └── functions.php   # Helper functions
├── pages/
│   ├── dashboard.html     # User dashboard
│   ├── login.html         # Login page
│   └── register.html      # Registration page
├── database/
│   └── schema.sql         # Database schema
├── images/                # Image assets
├── index.html             # Landing page
└── README.md              # This file
```

## Technologies Used

- **Frontend**: HTML5, CSS3, JavaScript
- **Backend**: PHP 8.x
- **Database**: MySQL
- **Maps**: Leaflet.js with OpenStreetMap
- **Icons**: Font Awesome

## Setup Instructions

### 1. Database Setup

1. Open MySQL/phpMyAdmin
2. Import `database/schema.sql`

### 2. Configuration

1. Open `php/config/database.php`
2. Update database credentials if needed:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'unigo_db');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

### 3. Run the Application

1. Place the `UniGo` folder in your web server's root (e.g., `htdocs` for XAMPP)
2. Start Apache and MySQL
3. Open `http://localhost/UniGo` in your browser

## Features

- User registration and authentication
- Ride matching and booking
- Real-time vehicle tracking with live map
- Seat booking for Kayola electric buses
- Digital payment integration (ready)
- Responsive design for mobile and desktop

## Default Login

- **Email**: driver@unigo.com
- **Password**: password
