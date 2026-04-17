CHAPTER 3
METHODOLOGY
Technical Background
The following technologies are suggested for the system:
• Web Application: The JuanRide web application is being developed using Next.js
14 and Node.js to create a simple and user-focused platform for both vehicle owners
and renters. Next.js 14 is a React-based framework that helps build responsive
interfaces while supporting server-side rendering (SSR) and static site generation
(SSG). These features contribute to faster page loading and better usability. Next.js also
allows the use of reusable components, which helps speed up development and makes
future updates easier.
Node.js, a JavaScript runtime built on Chrome’s V8 engine, powers the backend of the
application. Its event-driven setup is suitable for handling multiple users at the same
time. This part of the system is responsible for managing user requests, handling vehicle
listings, and processing transactions. Using Next.js 14 and Node.js together offers a
functional and responsive environment for the needs of JuanRide’s users.
• Mobile Application: To make the system accessible on mobile devices, JuanRide is
also being developed as a mobile application using Android Studio. Instead of creating
a fully separate mobile version, the mobile app will use a web view. This setup allows
users to access the full web platform through the app, without needing to switch
between two different systems. With this method, the mobile app delivers the same
features and functionality as the web version. Using Android Studio also ensures that
the app works smoothly on Android devices, providing convenience for users who are
always on the go.
• Database Management System: JuanRide uses MongoDB as the primary database
system, combined with Prisma to manage data more efficiently. MongoDB is a
document-based NoSQL database that works well with a wide variety of data types
such as vehicle details, user profiles, rental records, and payment history. It is flexible
and scalable, which fits the needs of the platform as it grows.
STI College Surigao 16
Prisma is an ORM (Object-Relational Mapping) tool that connects the database to the
web and mobile applications. It helps developers manage and access data more easily
by making database queries safer and more readable. This setup supports faster
development and improves data handling for both users and system administrators.
• Hosting and Deployment: The JuanRide platform is planned to be hosted using
DigitalOcean and GoDaddy. DigitalOcean provides the cloud hosting infrastructure
needed to keep the system available and stable. This means users can access the
platform at any time without major interruptions.
GoDaddy is used for domain registration, giving the platform an official and easy-toremember web address. Together, these services help ensure that the JuanRide website
runs smoothly and is accessible to both owners and renters.
• Styling and Design: To make the interface clean, modern, and easy to use, the system
is styled using Tailwind CSS and Shadcn/UI. Tailwind CSS is a utility-first framework
that lets developers build designs quickly using predefined classes. It supports
responsive layouts, making the platform work well on different screen sizes.
Shadcn/UI is a component library that provides ready-made UI elements like buttons,
input fields, and navigation bars. These components are accessible and easy to integrate,
helping the team build a better-looking and more user-friendly platform without starting
from scratch.
STI College Surigao 17
Requirements Analysis
In developing JuanRide, the goal is to provide a digital vehicle rental solution tailored
to the needs of both vehicle owners and renters, especially on Siargao Island. This
system will address current challenges in manual booking, availability tracking, and
communication by offering a centralized digital platform. The analysis below outlines
the core elements of the current problem and how the system addresses them:
Who – The People Involved:
• Vehicle Owners (individuals/businesses renting out vehicles)
• Renters (tourists and locals looking for rentals)
• Admins (manage listings, users, and reported issues)
What – The Business Activity:
• Vehicle rental process (listing, searching, booking, tracking)
• Digital alternative to manual and informal booking methods
• Central platform to streamline owner-renter transactions
Where – The Environment:
• Mainly used in Siargao Island (target area with tourism activity)
• Accessible on mobile phones and computers
• Works with internet access in both urban and remote areas
When – The Timing:
• Users can access and use the system anytime
• Bookings and availability updated in real-time
• Allows advanced and scheduled reservations
How – Current Procedures:
• Manual contact through calls, texts, or social media
• No consistent record of bookings or availability
• JuanRide will automate listings, bookings, and notifications
STI College Surigao 18
Requirements Documentation
The JuanRide system is a comprehensive digital platform designed to modernize and
optimize the vehicle rental experience, particularly in tourism-driven areas such as
Siargao Island. The system serves as a bridge between renters and vehicle owners,
offering seamless online booking, real-time vehicle availability tracking, secure digital
payments, and automated rental management. JuanRide aims to deliver a reliable,
efficient, and safe rental ecosystem. This document outlines the functional and nonfunctional requirements critical to delivering an effective and user-centered experience
for both renters and vehicle owners.
Functional Requirements
For Renters:
1.1 Real-Time Vehicle Browsing
• View a list of currently available vehicles filtered by type, location,
price, and availability date.
1.2 Online Booking & Instant Confirmation
• Reserve vehicles with instant system confirmation, minimizing
manual processing.
1.3 Advanced Filters
• Search and filter vehicles by category (scooter, car, van, etc.),
location, budget, duration, and ratings.
1.4 Transparent Pricing and Rental Terms
• Display clear pricing, rental duration options (hourly/daily/weekly),
insurance coverage, and policies before booking.
1.5 Online Payment Options
• Multiple payment channels including e-wallets (GCash, Maya),
bank transfers, and card payments.
1.6 Booking Notifications & Return Reminders
• Automated SMS/in-app notifications for booking confirmation, due
time, and return reminders.
1.7 In-App Chat Support
STI College Surigao 19
• Real-time messaging support for inquiries, issues, or negotiation.
1.8 Ratings & Reviews
• Submit and view ratings for both vehicles and owners.
1.9 Vehicle GPS Tracking
• Track current rental status and location (shared voluntarily or
required for certain listings).
1.10 Mobile-Friendly UI
• Fully responsive design optimized for smartphone and tablet use.
For Owners/Admin:
2.1 User Account Management
• The admin should be able to create, update, and delete user
accounts for both renters and vehicle owners.
• The admin should be able to assign user roles (e.g., renter, vehicle
owner) and manage permissions accordingly.
• The system should allow the admin to reset user passwords upon
request.
• Admin should be able to view detailed user profiles, including past
rental history, feedback, and ratings.
2.2 Booking Management System
• Accept, decline, or modify bookings with calendar synchronization.
2.3 Vehicle Listing Management
• Add and delete vehicles in the system for rental availability.
• Upload and manage photos of each vehicle for display to customers.
• Set and update the status of vehicles (e.g., available, rented, under
maintenance).
• Admin should be able to set pricing for each vehicle and define terms
and conditions for renting.
• Ensure all vehicles have complete and valid documents before
listing them in the system.
2.4 Real-Time Vehicle Availability Dashboard
STI College Surigao 20
• Overview of which vehicles are rented, booked, or idle.
2.5 Comprehensive Dashboard
• View earnings, booking stats, and vehicle condition reports.
2.6 GPS Vehicle Tracking
• Track vehicle location to prevent loss or misuse.
• The GPS tracker pinpoints the vehicle's location during
emergencies, when a renter faces an issue, or if the vehicle is
missing, enabling quick response and precise recovery.
2.7 Payment and Transaction Management
• The admin should have access to view all transactions made within
the system, including rental payments, refunds, and any additional
charges.
2.8 Feedback and Rating Management
• Admin should be able to monitor and manage feedback and ratings
left by renters and vehicle owners.
• The system should allow the admin to filter or sort feedback based
on vehicle, renter, or owner.
• Admin should be able to flag or remove inappropriate or fraudulent
reviews or ratings.
2.9 Vehicle Pricing
• The system supports dynamic pricing based on vehicle type.
• Transparent pricing tiers are shown to customers during the booking
process.
• A partial, non-refundable upfront payment is required to confirm a
booking, with the amount varying by vehicle type and total rental
cost.
• The remaining balance is paid upon vehicle pickup or according to
the owner’s rental terms.
3.1 Maintenance and Inventory Tracking
• Admin should be able to monitor and manage vehicle maintenance
schedules, ensuring that vehicles are regularly serviced and safe for
renters.
STI College Surigao 21
• The system should allow the admin to log vehicle maintenance
activities and update the status of vehicles under repair.
• Admin should be able to generate maintenance reports to track
recurring issues or costs.
3.2 Notifications and Alerts
• Admin should receive notifications for critical actions such as
booking requests, cancellations, payment issues, or vehicle
maintenance updates.
• The system should send automated notifications to users for
booking confirmations, payment receipts, maintenance alerts, or
feedback requests.
3.3 Reporting and Analytics
• Admin should be able to access a dashboard with key performance
indicators (KPIs) such as booking volume, revenue, and customer
satisfaction metrics.
• The system should allow the admin to generate detailed reports,
including user activity, transaction summaries, and maintenance
logs.
• Admin should have the ability to export reports in formats like
PDF or Excel.
• Monthly reports of income, rentals, customer feedback, etc.
3.4 System Configuration and Settings
• Admin should be able to configure system settings such as rental
rates, booking policies, and user permissions.
• The system should allow the admin to update the platform's terms
and conditions, privacy policy, and other legal documents.
• Admin should be able to manage payment gateway settings and
integrate with third-party payment processors.
Non-Functional Requirements
For Both Renter and Owner
1. Availability
STI College Surigao 22
• System should be accessible 24/7 via web and mobile.
Usability
• Clean, intuitive UI designed for tech and non-tech-savvy users alike.
Performance
• Bookings and page responses should load in under 3 seconds.
Security
• All user data must be encrypted. Include secure login, verified ID
upload, and payment processing.
Scalability
• Should support scaling to more locations or thousands of users.
Reliability
• Maintain >99% uptime with a support system in place for outages.
Data Backup
• Regular backups to prevent data loss.
Legal Compliance
• Ensure proper handling of data per local laws(Data Privacy Act PH).
For Administrator
1. Usability
• The system should have an intuitive and easy-to-navigate interface
for the admin, ensuring minimal training requirements.
• The admin interface should be responsive and mobile-friendly,
allowing access from various devices including smartphones and
tablets.
2. Performance
• The system should be capable of handling a high volume of user
traffic and bookings simultaneously, ensuring no performance
degradation during peak usage periods.
• The admin dashboard should load promptly, even when accessing
large datasets such as transaction history or user feedback.
3. Security
• The system should implement strong user authentication methods
(e.g., multi-factor authentication) for admin access to ensure data
security.
STI College Surigao 23
• All sensitive data, including user information and payment details,
should be encrypted both in transit and at rest.
• The system should log all admin activities for auditing purposes,
including user account changes and financial transactions.
4. Scalability
• The system should be scalable to accommodate growth in both the
number of users and vehicles listed on the platform.
• Admin should be able to scale the system by adding more rental
locations, vehicles, and users without performance issues.
5. Reliability
• The system should be designed for high availability, with minimal
downtime for maintenance and updates.
• In case of a system failure, data should be recoverable, and users
should be notified of any issues promptly.
• The system should be able to back up critical data regularly to
prevent data loss.
6. Compatibility
• The admin system should be compatible with major web browsers
(e.g., Chrome, Firefox, Safari, Edge).
• The system should also support integration with third-party
platforms, such as payment gateways and external booking
systems.
7. Maintainability
• The system should be modular and well-documented, making it
easier for developers to update or extend features in the future.
• Admin should be able to perform basic maintenance tasks, such as
adding new vehicles, adjusting rates, or updating contact
information, without requiring developer support.
8. Compliance
• The system must comply with local regulations related to vehicle
rentals, payment processing, and user data protection.
• Admin should ensure the platform adheres to relevant laws and
regulations in Siargao Island and any future regions where
JuanRide operates.
STI College Surigao 24
Design of Software, System, Product, and/or Processes
Waterfall Development Methodology
The development of JuanRide: A Digital Vehicle Rental System follows the Waterfall
Software Development Life Cycle (SDLC) model, chosen for its systematic and
sequential approach. This section introduces how the project’s software, system, and
processes were designed based on clearly defined requirements. The Waterfall model
allows each phase—from planning and analysis to design, coding, testing, and
maintenance—to be completed in a logical order. This methodology supports a
structured workflow that fits well within academic schedules and ensures that each
component of the system is built and documented with clarity and consistency.
Figure 1
Software Development Life Cycle (SDLC) Model
1. Requirements
Identify inefficiencies in Siargao’s manual vehicle rental system through stakeholder
interviews and surveys. Define functional needs (e.g., online booking, real-time
tracking, secure payments) and non-functional needs (e.g., scalability, 24/7 availability).
Document user requirements for renters (vehicle browsing, reviews) and owners (fleet
management, analytics) to create a centralized digital platform.
Requirements
Design
Implementation
Testing
Development
Maintenance
STI College Surigao 25
2. Design
Develop system architecture, including user interface wireframes for web/mobile,
MongoDB database schema for vehicle/user data, and process flows for
booking/payment. Plan responsive layouts using Next.js and Tailwind CSS. Ensure
scalability and security (encrypted data, user authentication) to meet Siargao’s
tourism-driven needs.
3. Implementation
Code the platform using Next.js for the web frontend, Node.js for backend logic, and
Android Studio for a mobile web view. Integrate MongoDB with Prisma for data
management. Build features like vehicle listings, booking forms, GPS tracking, and
payment modules. Develop APIs for seamless system integration.
4. Testing
Conduct unit testing for individual modules (e.g., booking, payment), integration
testing for combined components, and user acceptance testing (UAT) with Siargao
stakeholders. Address bugs and performance issues to ensure reliability, fast load
times, and compliance with functional requirements.
5. Development
Host the platform on DigitalOcean for cloud stability and register the domain via
GoDaddy. Launch the web and mobile app for Siargao users, ensuring 24/7 accessibility.
Provide initial user training for renters and owners to adopt the system effectively.
6. Maintenance
Monitor system performance post-launch, fixing bugs and applying security updates.
Implement user-requested enhancements (e.g., new features) and scale the platform for
additional locations. Conduct regular data backups and ensure compliance with local
data privacy laws.