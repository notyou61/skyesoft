# 📅 **Time Interval Standards**

## 🏷️ Purpose  
Establish standardized time intervals used across all Skyesoft modules to ensure consistent reporting, accurate attendance tracking, and precise performance analytics.

## 🕒 **Defined Intervals**  
- **Workday**:  
  - Used to calculate active business days for order completion, service call response times, and employee attendance.  
  - Tracks office, shop, and service/install start and end times.
- **Weekend**:  
  - Non-standard workdays. Useful for analyzing overtime, emergency service calls, and off-hours work.  
- **Holiday**:  
  - Official company holidays. Used to pause standard time tracking for orders and attendance.

## 📈 **Applications**  
- **Order Management**:  
  - Calculate the number of active Workdays required to complete an order.  
- **Service Tracking**:  
  - Measure turnaround times based on Workday intervals only, excluding weekends and holidays.  
- **Attendance Suite**:  
  - Enforce check-ins based on scheduled start and end times for each department:  
    - Office Staff  
    - Shop Staff  
    - Service/Install Teams  

## 📅 **Holiday Calendar Management**  
- Maintain a centralized holiday calendar to be referenced by all modules.  
- Allow leadership to update holidays dynamically, with immediate effect on time calculations.

## 📺 **Electronic Bulletin Board (EBB) Display**  
- **Countdown Timer**:  
  - Displays time remaining in the current interval (e.g., "2 hours 15 minutes remaining in Workday").  
- **Advanced EBB Display Ideas**:  
  - Include a visual progress bar alongside the countdown to quickly communicate interval progress.  
  - **Example:**  
    - `"Workday: 2h 15m remaining [██████░░░░]"`  
- **Real-Time Updates**:  
  - Automatically updates based on interval transitions (Workday → Weekend → Holiday).

## 🛠️ **Implementation Notes**  
- Store official start and end times for each shift category (Office, Shop, Service/Install).  
- Ensure countdown calculations account for time zone settings and daylight savings adjustments if applicable.  
- Integrate interval awareness into all KPI calculations and SLA reporting.  
