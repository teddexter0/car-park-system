# Strathmore Smart Parking & Access Management System

A Software Engineering class prototype built with PHP, JavaScript and MariaDB. It shows parking availability by zone using arrivals and departures recorded at the gate. All accounts and parking records are fictional.

Use the current demo link shared by the group or follow the XAMPP setup below.

The link opens the read-only parking map without an account. Choose **Demo sign-in** to simulate a sensor event as a student or use the security and admin controls.

## What each role can do

| Role | Tasks |
| --- | --- |
| Student | View and simulate occupying one bay at a time. |
| Staff | View available spaces by zone. |
| Gate officer | Check vehicles in and out, record visitors, transfer vehicles between zones and correct missed checkouts. |
| Administrator | Book or close individual bays, manage zones and view reports and the audit log. |

General parking is first come, first served. Green means vacant, yellow previews a gate assignment, red means occupied, grey means unavailable and blue means booked. On the dashboard, a student can click a green tile to simulate a sensor detecting a car. Clicking the same tile again simulates departure. Administrators can select a bay and hold it for a named booking, such as a directors’ meeting, or close it. Booking and closure edits stay in effect until an administrator clears them. Gate records track occupancy by zone and share the same available capacity.

## Run with XAMPP

1. Copy this folder to `C:\xampp\htdocs\car-park-system`.
2. Start **Apache** and **MySQL** in XAMPP.
3. In phpMyAdmin, import `database.sql` once to create and seed the demo database. Then select `strathmore_smart_parking` and import `parking-bays.sql`. For an existing database, import only `parking-bays.sql` into that database to preserve the records.
4. Open [localhost/car-park-system](http://localhost/car-park-system/).

| Role | Demo email |
| --- | --- |
| Student | `student@parking.test` |
| Staff | `staff@parking.test` |
| Gate officer | `security@parking.test` |
| Administrator | `admin@parking.test` |

All demo accounts use `ParkingDemo26!`.

## Quick walkthrough

1. Sign in as the gate officer and open **Gate operations**.
2. Choose **Visitor** and enter fictional visit details and a vehicle registration such as `DEMO701`.
3. Select **Visitor Parking** and look for the yellow assignment preview. Check the vehicle in. A fresh database shows availability drop from 12 to 11.
4. Find the vehicle under **Active sessions** and check it out. Availability returns to 12.
5. Sign in as the administrator to view zones and the two events in the audit log.

## Entity relationship diagram

```mermaid
erDiagram
    MOCK_AMS o|--o| USERS : links
    USERS o|--o{ PARKING_SESSIONS : drives
    VISITORS o|--|| PARKING_SESSIONS : visits
    PARKING_ZONES ||--o{ PARKING_SESSIONS : contains
    PARKING_ZONES ||--o{ PARKING_BAYS : contains
    USERS o|--o| PARKING_BAYS : simulates_occupancy
    USERS o|--o{ AUDIT_LOGS : performs

    MOCK_AMS {
        int id PK
        string institutional_id UK
        enum category
    }
    USERS {
        int id PK
        int ams_id FK
        string email UK
        enum role
        string password_hash
    }
    VISITORS {
        int id PK
        string name
        string purpose
        string destination
        boolean id_inspected
        boolean id_retained
    }
    PARKING_ZONES {
        int id PK
        int capacity
        boolean active
    }
    PARKING_SESSIONS {
        int id PK
        int user_id FK
        int visitor_id FK
        int zone_id FK
        string vehicle_plate
        string active_plate UK
        datetime checkin_at
        datetime checkout_at
    }
    PARKING_BAYS {
        int zone_id PK, FK
        int bay_number PK
        enum status
        int student_id FK, UK
        string booking_label
    }
    AUDIT_LOGS {
        int id PK
        int actor_id FK
        string action
        string entity_type
        int entity_id
        json metadata
    }
```

Each parking session belongs to either a campus user or a visitor and is assigned to one parking zone.

## Gate check-in sequence

```mermaid
sequenceDiagram
    actor Officer
    participant Gate as Gate operations
    participant API as PHP API
    participant DB as MariaDB
    Officer->>Gate: Select driver type, vehicle and zone
    activate Gate
    Gate->>API: Submit check-in
    activate API
    API->>API: Verify role and validate fields
    API->>DB: Start transaction and check zone capacity
    activate DB
    alt Space available and vehicle has no active session
        API->>DB: Save parking session and audit event
        API->>DB: Commit
        API-->>Gate: Confirm check-in
        Gate->>API: Refresh availability and active sessions
    else Zone unavailable or vehicle already checked in
        API->>DB: Roll back
        API-->>Gate: Explain why check-in failed
    end
    deactivate DB
    deactivate API
    Gate-->>Officer: Show result
    deactivate Gate
```
