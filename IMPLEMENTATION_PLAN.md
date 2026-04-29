# Time Slot Implementation Plan

## Current State
- Uses broad `time_period` enum (morning/afternoon)
- No specific start/end times stored
- Sessions assumed to be 2 hours by default
- Date validation prevents same-day bookings (`after:today`)

## Required Changes

### 1. Database Schema Changes
**Add new migration to:**
- Add `start_time` (time column) to bookings table
- Add `end_time` (time column) to bookings table  
- Keep `time_period` for backward compatibility or remove later
- Modify availabilities table similarly

### 2. Backend Changes
**TimePeriod Enum**: Update to support hourly slots
**Booking Model**: Add start_time, end_time fields
**BookingController**: 
- Change validation from `time_period` to `start_time`
- Calculate end_time based on duration (1 hour sessions)
- Allow same-day bookings if slot available
- Check instructor availability for specific time slot

**Availability Model**: Update to support hourly time slots

### 3. Frontend Changes
**Create.vue**: 
- Replace 2-button period selector with dropdown/grid of hourly slots
- Filter available slots based on instructor availability
- Show only slots that are still open for booking

### 4. Additional Features
- **Same-day booking**: Remove `after:today` restriction, check if time slot has passed
- **Tide API**: Create TideService to fetch real tide data
- **Board recommendations**: Add helper based on height/weight
- **QR Codes**: Install phpqrcode library, generate real QR codes

## Implementation Priority
1. ✅ Database migration for start_time/end_time
2. ✅ Update Booking model
3. ✅ Update BookingController validation
4. ✅ Update Create.vue frontend
5. ✅ Same-day booking logic
6. ⏳ Tide API integration
7. ⏳ Board size recommendations
8. ⏳ QR code generation
