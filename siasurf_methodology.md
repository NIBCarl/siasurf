CHAPTER 3
METHODOLOGY
Technical Background
The following technologies are used in the development of the system:
• **Web Application Framework:** The SiaSurf platform is developed using Laravel 11, a PHP-based web application framework built on PHP 8.2+. Laravel follows the Model-View-Controller (MVC) architectural pattern with an additional Service Layer for business logic, providing a structured and organized codebase. Laravel is chosen for its battle-tested ecosystem, built-in authentication system, Eloquent ORM for database interactions, queue system for background processing, and native WebSocket support through Laravel Reverb. The framework enables rapid development while maintaining strong security practices, including built-in CSRF protection, input validation, and parameterized queries to prevent SQL injection.

• **Frontend Framework:** The user interface is built using Vue.js 3 combined with Inertia.js. Vue.js 3 utilizes the Composition API with TypeScript support to create reactive, component-based user interfaces. Inertia.js serves as the bridge between the Laravel backend and Vue.js frontend, delivering a Single Page Application (SPA) experience while maintaining server-side routing and shared validation. This combination eliminates the need for a separate API layer, reduces development complexity, and provides faster page loads with server-side rendering (SSR) capabilities for search engine optimization.

• **Database Management System:** SiaSurf uses MySQL 8+ as its relational database management system, accessed locally through XAMPP during development. MySQL is selected for its wide compatibility with Laravel, FULLTEXT indexing for instructor search functionality, JSON column support for flexible data storage, and utf8mb4 character encoding for full Unicode support. Eloquent ORM provides an expressive, fluent interface for interacting with the database, enabling safe and readable query construction with built-in query optimization.

• **Styling and UI Design:** The platform's visual design is implemented using Tailwind CSS 3.4+, a utility-first CSS framework that enables a mobile-first, responsive approach essential for tourists accessing the platform from mobile devices at the beach. The UI follows an ocean and surf-inspired custom theme. Headless UI provides accessible, unstyled component primitives, while Heroicons supplies a consistent iconography system. The frontend follows Atomic Design principles, organizing components into Atoms (BaseButton, StatusBadge), Molecules (SearchBar, BookingDatePicker), Organisms (InstructorCard, BookingForm), and Layout components for maximum reusability.
STI College Surigao 16
• **Authentication and Authorization:** User authentication is handled by Laravel Breeze, providing login, registration, email verification, and password reset functionalities with an Inertia/Vue scaffold. Role-Based Access Control (RBAC) is implemented using the Spatie Laravel Permission package, defining three roles—student, instructor, and admin—with granular permissions such as bookings.create, instructors.verify, and analytics.view. Laravel Sanctum is also configured for token-based API authentication to support future mobile application expansion.

• **Real-Time Communication:** Real-time features are powered by Laravel Reverb, Laravel's first-party WebSocket server. Reverb enables instant notifications for booking events (BookingCreated, BookingConfirmed, BookingCancelled), instructor status updates, payment confirmations, and safety incident alerts. It operates through private channels (instructors.{id}, students.{id}) and presence channels (admin-dashboard) using Laravel Echo with the Pusher protocol on the client side. Being self-hosted, Reverb eliminates recurring third-party WebSocket fees.

• **Payment Gateway:** Payment processing is integrated through the PayMongo API for GCash digital wallet transactions. The payment flow involves creating a Payment Intent, generating a GCash checkout URL, processing confirmation webhooks with signature verification, and handling failed or refunded payments. The system also supports cash-on-hand payments, where instructors manually mark payments as received and the system generates a receipt. All payment transactions maintain an immutable audit trail for accountability.

• **File Storage:** Instructor certificates (BLS, WaSAR, Surf Skills—PDF only, max 10MB), profile photos, QR code images, waiver PDFs, and receipt documents are stored securely on AWS S3 using private buckets with signed URLs (15-minute expiration). Files undergo virus scanning via ClamAV before storage. CloudFront or Cloudflare CDN is used for efficient image delivery.

• **QR Code Generation:** Unique QR codes are generated for each verified instructor using the SimpleSoftwareIO/Simple-QRCode PHP package. Each QR code is static, generated once upon instructor verification, and encodes the instructor's unique ID, name, certification level, and a verification URL. Tourists and resort staff can scan the QR code to instantly verify an instructor's legitimacy and view their profile. The html5-qrcode JavaScript library handles QR scanning on mobile devices for walk-in bookings.
STI College Surigao 17
• **Queue and Background Processing:** Redis serves as the queue driver for background job processing. Background jobs include sending email notifications (booking confirmations, review reminders, status changes), generating PDFs (waivers, receipts), processing image thumbnails, and running daily certificate expiration checks. The Laravel Scheduler manages recurring tasks through cron jobs.

• **Caching Strategy:** Redis is also employed as the caching layer. Instructor listings are cached for 5 minutes, pricing rules for 24 hours, and safety rules are permanently cached. User sessions are backed by Redis for multi-server readiness. Real-time availability data is primarily served through WebSocket channels with a 1-hour backup cache.

• **Document Generation:** PDF generation for digital liability waivers, parental consent forms, and payment receipts is handled by the barryvdh/laravel-dompdf package, rendering Blade templates into downloadable PDF documents. Excel report exports for analytics, revenue data, and session logs are produced using the Maatwebsite/Excel package.

• **Hosting and Deployment:** The production environment is hosted on DigitalOcean, managed through Laravel Forge for automated deployments triggered by Git pushes. The server stack includes Nginx as the web server with PHP-FPM, Redis 7 for caching and queues, and Laravel Reverb running as a separate WebSocket process. SSL certificates are provided by Let's Encrypt with automatic renewal. Local development uses XAMPP (Apache + MySQL + PHP).

Requirements Analysis
In developing SiaSurf, the goal is to provide a centralized web-based platform that digitalizes instructor verification, standardizes booking processes, and enforces safety monitoring for surfing tourism in Siargao Island. This system will address current challenges in uncertified instructors, disorganized bookings, lack of standardized pricing, inadequate safety protocols, and insufficient post-lesson monitoring by offering a comprehensive digital solution. The analysis below outlines the core elements of the current problem and how the system addresses them:
**Who – The People Involved:**
• Students/Tourists (individuals seeking surf lessons in Siargao Island)
• Verified Surf Instructors (SISA/ISA-certified professionals offering surf lessons)
• Administrators — SISA/LGU (manage instructor verification, safety monitoring, and operations)
**What – The Business Activity:**
• Instructor verification and credential management with QR code generation
• Centralized booking system with real-time availability and walk-in QR flow
• Automated safety and skill-level matching to prevent accidents
• Standardized pricing enforcement with digital waivers and GCash/cash payments
• Post-session feedback, performance monitoring, and safety incident logging
**Where – The Environment:**
• Primarily used in Siargao Island, Surigao del Norte, Philippines
• Accessible via web browsers on mobile phones and computers
• Mobile-first design for tourists at the beach; desktop-optimized for admin dashboard
**When – The Timing:**
• Users can access the platform at any time (24/7 availability)
• Bookings, availability, and session tracking updated in real-time via WebSocket
• Advance reservations and same-day walk-in bookings supported
**How – Current Procedures:**
• Informal walk-ins and direct negotiations between tourists and instructors at the beachfront
• No centralized record of bookings, availability, or instructor credentials
• SiaSurf will automate verification, booking, safety enforcement, payments, and monitoring
STI College Surigao 18
Requirements Documentation
The SiaSurf system is a comprehensive web-based platform designed to modernize and secure surfing tourism operations in Siargao Island. The platform serves as the centralized hub connecting tourists seeking surf lessons with SISA-verified instructors, while providing administrators with real-time safety monitoring and analytics tools. SiaSurf aims to deliver a safe, efficient, and accountable surfing lesson ecosystem through instructor verification with QR codes, automated safety rules enforcement, standardized pricing, digital waivers, and post-session performance monitoring. This document outlines the functional and non-functional requirements critical to delivering an effective and user-centered experience for students, instructors, and administrators.
Functional Requirements
**For Students/Tourists:**
**1.1 Instructor Search and Discovery**
    • Search for verified surf instructors filtered by location, availability, price range, and certification level.
    • Full-text search by instructor name, bio, and specialties using MySQL FULLTEXT indexing.
    • Sort results by recommended, price (low-high), or experience level.
    • View instructor profiles with photo, bio, certifications, pricing, and real-time availability calendar.
**1.2 Online Booking with Safety Validation**
    • Select skill level (beginner, intermediate, advanced), enter age, and choose date and time period (morning: 6AM–12PM, or afternoon: 12PM–6PM).
    • Mandatory surfboard ownership check ("Do you have your own surfboard?").
    • System automatically validates all safety rules before booking confirmation, including instructor-to-student ratios, age restrictions, surf spot difficulty matching, and certification-level matching.
    • Booking blocked with clear error message if any safety requirement is not met.
**1.3 Digital Waiver and Consent**
    • Sign a digital liability waiver (assumption of risk, release of liability, medical consent) before booking confirmation via electronic signature (canvas draw or type).
    • Parental consent form automatically triggered when student age is under 18, including parent/guardian information, emergency contact, and medical details.
**1.4 Payment Options**
    • Pay via GCash (through PayMongo integration) or cash on hand.
    • Booking held for 15 minutes during GCash payment processing.
    • Automated PDF receipt generation emailed to student upon payment.
**1.5 Walk-in QR Booking**
    • Scan an instructor's QR code at the beach to view their profile and fast-track a booking.
    • QR scan redirects to registration/login, pre-fills instructor selection from QR data.
**1.6 Post-Session Feedback**
    • Submit written review (minimum 20 characters) with optional photo upload after session completion.
    • Review link sent via email 2 hours after lesson.
    • One review per booking with a 24-hour edit window.
STI College Surigao 19
**1.7 Real-Time Notifications**
    • Receive instant booking confirmation, payment status, and session updates via WebSocket and email notifications.
**For Instructors:**
**2.1 Availability Management**
    • Set weekly availability schedule with morning/afternoon time periods per day.
    • Block specific dates/times for vacation or personal time.
    • Confirm or adjust lesson times based on real-time tide conditions.
    • Time slots automatically blocked once a reservation is confirmed.
**2.2 Booking Management**
    • Receive real-time booking notifications via WebSocket.
    • View upcoming bookings with student details.
    • Accept or decline booking requests within 2 hours.
    • Cancel or reschedule bookings (admin approval required if less than 24 hours).
**2.3 Session Tracking**
    • Mark student attendance and click "Start Session" to trigger a real-time countdown timer based on lesson duration (default 2 hours).
    • Session details logged: instructor, student, location, duration, start/end time.
    • Mark cash payments as received after lesson completion.
**2.4 Reviews and Feedback**
    • View and respond to student reviews (one response per review).
    • Feedback records automatically stored in instructor profile for SISA monitoring.
**For Administrators (SISA/LGU):**
**3.1 Instructor Registration and Verification**
    • Register verified surf instructors with certificate uploads (BLS, WaSAR, Surf Skill, SISA/ISA IDs — PDF only, max 10MB).
    • Review certificates and approve/reject with notes.
    • Upon approval: instructor status set to active, unique QR code generated, notification sent.
    • Update instructor status: active, suspended, or inactive with email notification.
    • Certification expiration alerts sent 30 days before expiry.
    • Status change history logged for complete audit trail.
**3.2 Safety and Strike Management**
    • Log safety incidents: type (injury, near-miss, rule violation), severity (minor/major/critical), people involved, description, and associated booking.
    • Automated strike counting: minor = 1 strike, major = 2 strikes, critical = 3 strikes (immediate suspension).
    • Instructor profile automatically hidden from public view after 3 strikes (1-month suspension).
    • Email alerts sent for high-severity incidents.
STI College Surigao 20
**3.3 Session Monitoring Dashboard**
    • View completed lessons, active instructors, real-time activity feed, and booking history.
    • Filter and search session logs.
    • Monitor ongoing sessions with real-time countdown timers.
**3.4 Analytics and Reporting**
    • Dashboard with overview cards: total bookings, active instructors, revenue, and incidents.
    • Instructor performance metrics: booking completion rate, cancellation rate, and safety incident count.
    • Revenue reports by instructor, period, and payment method.
    • Export reports to Excel (via Maatwebsite/Excel) and PDF (via laravel-dompdf).
**3.5 Booking and Payment Management**
    • View all bookings with filters by status, date, and instructor.
    • Track payment statuses (pending, completed, failed, refunded).
    • Process refunds through admin interface only.
    • Cash payments must be marked within 24 hours.
**3.6 Pricing Configuration**
    • Enforce minimum base rates (₱600/hour, ₱1,200 for standard 2-hour package).
    • Configure higher-tier pricing for Level 3/ISA certified instructors (₱1,500–₱2,000).
    • Manage premium add-ons: video analysis (+₱500), photography (+₱800), weekly discount (10%), monthly discount (20%).
    • Prevent instructors from setting rates below minimum.
**3.7 Review Moderation**
    • Monitor and moderate student reviews.
    • Flag or remove inappropriate or fraudulent reviews.
Non-Functional Requirements
**For All Users (Students, Instructors, and Administrators)**
**1. Availability**
    • System shall be accessible 24/7 via web browsers on desktop and mobile devices.
STI College Surigao 21
**Usability**
    • Mobile-first responsive design optimized for tourists accessing the platform from smartphones at the beach.
    • Clean, intuitive interface using ocean/surf-themed color palette (blues, teals, sandy neutrals).
    • WCAG 2.1 AA accessibility compliance.
    • Clear, user-friendly error messages with loading states and progress indicators.
**Performance**
    • Page load time under 3 seconds on 3G connections.
    • WebSocket latency under 500ms for real-time updates.
    • Support 100+ concurrent users.
    • Database queries optimized to under 100ms.
    • Image optimization and lazy loading implemented.
**Security**
    • HTTPS enforced everywhere with HSTS headers.
    • OWASP Top 10 compliance including XSS prevention (Vue auto-escaping, Laravel escaping), SQL injection prevention (parameterized queries), and CSRF protection on all forms.
    • File upload validation (MIME type, size limits, virus scanning via ClamAV).
    • Rate limiting on authentication and critical endpoints.
    • AES-256 encryption for sensitive data at rest.
    • Audit logging for all sensitive operations (bookings, payments, status changes).
**Scalability**
    • Horizontal scaling ready with stateless architecture.
    • Redis for session storage (multi-server ready).
    • CDN for static asset delivery.
**Reliability**
    • 99.9% uptime target.
    • Automated daily backups.
    • Database transaction integrity maintained.
    • Queue worker retry logic for failed background jobs.
    • Graceful error handling with fallback mechanisms.
**Data Privacy and Legal Compliance**
    • Proper handling of personal data per the Data Privacy Act of 2012 (Republic Act 10173).
    • Digital waivers and consent forms stored securely for 7 years.
    • Immutable audit trail for all financial transactions.
STI College Surigao 22
**Compatibility**
    • Support modern browsers: Chrome, Firefox, Safari, Edge (last 2 versions).
    • Mobile browser support: iOS Safari, Chrome Mobile.
    • QR code scanning compatible with standard mobile device cameras.
**For Administrators**
**1. Usability**
    • The admin dashboard shall have an intuitive, data-rich interface with overview cards, real-time activity feeds, and charted analytics.
    • The admin interface shall be responsive, accessible from various devices including smartphones and tablets.
**2. Performance**
    • The admin dashboard shall load promptly even when accessing large datasets such as transaction histories, session logs, or instructor records.
    • Report generation (Excel/PDF) shall complete within reasonable time frames for large data exports.
**3. Security**
    • Admin access protected by role-based middleware ensuring only authorized personnel (SISA/LGU) can access management features.
    • All admin activities logged for auditing purposes, including instructor status changes, incident reports, and financial transactions.
**4. Maintainability**
    • The system shall follow a modular architecture (MVC + Service Layer) with well-documented code for future updates and feature additions.
    • Admin shall be able to configure pricing rules, manage instructor statuses, and update platform settings without requiring developer support.
STI College Surigao 23
Design of Software, System, Product, and/or Processes
Waterfall Development Methodology
The development of SiaSurf: Smart Booking and Instructor Verification Platform with Real-time Safety Integration and Monitoring follows the Waterfall Software Development Life Cycle (SDLC) model, chosen for its systematic and sequential approach. This section introduces how the project's software, system, and processes were designed based on clearly defined requirements. The Waterfall model allows each phase—from planning and analysis to design, coding, testing, and maintenance—to be completed in a logical order. This methodology supports a structured workflow that fits well within academic schedules and ensures that each component of the system is built and documented with clarity and consistency.
Figure 1
Software Development Life Cycle (SDLC) Model
**1. Requirements**
Identify critical gaps in Siargao Island's surfing tourism through stakeholder consultations with SISA officials, surf instructors, and tourists. Define functional needs including instructor verification with QR code generation, centralized booking with real-time safety validation, automated skill-level matching, standardized pricing, digital waivers, and post-session monitoring. Define non-functional needs such as mobile-first responsiveness, real-time WebSocket communication, 24/7 availability, and data privacy compliance. Document user requirements for three distinct roles: students/tourists (search, book, pay, review), instructors (manage availability, track sessions, receive payments), and administrators (verify instructors, monitor safety, generate analytics).
Requirements
Design
Implementation
Testing
Development
Maintenance
STI College Surigao 24
**2. Design**
Develop the system architecture following a monolithic MVC pattern with a Service Layer using Laravel 11. Design the MySQL 8 relational database schema covering 11 core entities: Users, InstructorProfiles, Certificates, SurfSpots, Availabilities, Bookings, Payments, Waivers, Reviews, SafetyIncidents, and Roles/Permissions. Create user interface wireframes for three role-specific dashboards using Vue.js 3 with Inertia.js and Tailwind CSS following Atomic Design principles. Plan the Safety Rules Engine to enforce non-overridable instructor-to-student ratios, age restrictions, and surf spot difficulty restrictions. Design WebSocket event architecture using Laravel Reverb for real-time booking, payment, and safety incident notifications. Plan PayMongo integration for GCash payment processing and AWS S3 for secure file storage with signed URLs.
**3. Implementation**
Code the platform using Laravel 11 for backend logic, Vue.js 3 with Inertia.js for the frontend SPA experience, and Tailwind CSS for mobile-first responsive styling. Implement the instructor verification module with certificate uploads, admin approval workflow, and QR code generation using SimpleSoftwareIO/Simple-QRCode. Build the centralized booking system with real-time availability, multi-step booking flow, automated safety validation via the SafetyService, and walk-in QR scanning. Integrate PayMongo API for GCash payments with webhook signature verification and implement cash-on-hand payment tracking. Develop the digital waiver system with electronic signature capture and PDF generation via laravel-dompdf. Implement the 3-strike safety enforcement system with the SafetyIncidentObserver. Build the admin dashboard with analytics charts, session monitoring, and Excel/PDF report exports. Set up Laravel Reverb for WebSocket real-time notifications and Redis for queue processing and caching.
**4. Testing**
Conduct unit testing for individual modules (SafetyService, PaymentService, BookingService) using PHPUnit and Pest. Perform integration testing for combined components including the booking-payment-waiver flow, instructor verification-QR generation pipeline, and safety rule enforcement across booking scenarios. Execute user acceptance testing (UAT) with SISA stakeholders, sample instructors, and tourist volunteers to validate usability and correctness. Test WebSocket real-time notification delivery, QR code scanning on various mobile devices, and GCash payment processing in PayMongo sandbox environment. Address bugs, performance bottlenecks, and security vulnerabilities to ensure reliability, fast load times, and compliance with all functional and safety requirements.
**5. Development**
Host the platform on DigitalOcean managed through Laravel Forge for automated Git-push deployments. Configure the production server with Nginx, PHP-FPM, Redis 7, and Laravel Reverb as a separate WebSocket process. Set up SSL via Let's Encrypt with automatic renewal. Launch the web application for Siargao Island users, making it accessible across desktop and mobile browsers. Provide initial training sessions for SISA administrators on instructor verification workflows and dashboard usage, and onboarding guides for instructors on managing their availability and profiles.
**6. Maintenance**
Monitor system performance post-launch, addressing bugs and applying security patches. Track platform adoption metrics including instructor registrations, booking volume, and safety incident reduction rates. Implement user-requested enhancements and feature updates based on SISA feedback and tourist usage patterns. Conduct regular automated database backups and ensure continued compliance with the Data Privacy Act of 2012. Plan for future scalability including mobile native applications, multi-language support, and expansion to other Philippine surf destinations.
