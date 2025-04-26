Sign Permit Service #notcomplete #inprogress
> Website to store & track sign permit applications.
> 

- Goals # #inprogress 
  > The site is used to store files, apply for permits, manage their application & track their progress.
  > 
- Functions #notcomplete
  > What the site will do using the CRUD operations of persistent storage.
  > 
  - Data Entry #notcomplete 
    > Creating or editing the database records.
  - Save Files #notcomplete 
    > Uploading images / files.
  - Produce Reports #notcomplete 
    > These can be emails or printed.
- Structure #notcomplete 
  > Website & database details.
  - Website #notcomplete
    > Controls on the site.
    - Grid #notcomplete #inprogress
      > Links to modal pages.  These can be sort and filter as needed,
      > 
      - Actions #notcomplete 
        > All activity on the website is tracked & classified 
        > 
      - Entities #notcomplete
      - Locations #notcomplete 
      - Orders #notcomplete 
      - Contacts #notcomplete
      - Applications #notcomplete 
    - Modal Page #notcomplete
      > Used to perform CRUD functions.
      > 
    - Live Dashboard #complete
      > Provides key performance indicators and other relevant data in real-time.
      - Alerts
        > Messages concerning events taking place on the site.
        > 
        - Login / Logout
          > Notes the user has logged in or out of the site.
        - CRUD Actions
          > Message of real time updates to the database records. 
          > 
        - Key Performance Indicators (KPI)
          > Showcased metrics of operations.
          - Applications Total Count
            > Number of applications entered.
          - Current Applications Count
            > Count of the applications current open.
            > 
          - Average Turn-Around Time
            > The overall average turn around of applications.
            > 
      - Intervals
        > The current status of the progression of certain time periods.
        - Current Day
          > The length of the current day.
          > 
        - Workday
          > What portion of the workday is and its duration.
          > 
        - Holiday
          > When & how long till the next holiday.
          > 
      - Chatgpt
      - Sign code check
    - Reports #notcomplete
      > Used to select a report and print it.
      > 
  - Database #complete
    > The database stored in a serialized file on the server.
    > 
    - CRUD Action Types #complete
      > The type of CRUD actions a user can make
      - CRUD ActionType ID #complete
        > The unique value for the event type.
        > 
      - Create #complete
        > Making a new record.
        > 
        - Login #complete 
        - Logout #complete 
        - Entity #complete 
        - Contact #complete
        - Order #complete
        - Application #complete
        - Application Fee #complete
        - Application Note #complete
      - Read #complete
        > Opening a record.
        - Entity #complete 
        - Contact #complete 
        - Order #complete 
        - Application #complete 
        - Application Note #complete 
      - Update #complete
        > Making changes to a record.
        - Entity #complete 
        - Contact #complete 
        - Order #complete 
        - Application #complete 
        - Application Fee #complete 
        - Application Status #complete 
        - Application Note #complete 
      - Delete #complete
        > Used to cancel a record which is not longer valid.
        - Entity #complete 
        - Contact #complete 
        - Order #complete 
        - Application #complete 
        - Application Note #complete 
    - Actions #complete
      > The CRUD Actions taken by users made on the site.
      - Action ID #complete
        > The unique number for the event.
      - Action Timestamp #complete
        > The datetime when the event took place.
      - Action CRUD Type ID #complete 
        > The CRUD operation that took place.
        > 
      - Contact ID #complete 
        > The contact that performed the event.
      - Action Latitude #complete
        > Latitude where the event occurred.
        > 
      - Action Longitude #complete
        > Longitude where the event occurred.
    - Locations #complete
      > Typically each vendor has one location.
      - Location ID #complete
        > A unique ID for the location.
        > 
      - Location Name #complete
        > A name to identify the location.
        > 
      - Location Place ID #complete
        > The Google place ID for the location.
        > 
      - Location Latitude #complete
        > The location latitude.
      - Location Longitude #complete
        > The location longitude.
      - Location Address #complete
        > Address of the location
        > 
      - Location Address Suite #complete
        > The suite of the address (if needed).
      - Location City #complete
        > Municipality of the location. 
      - Location State #complete
        > State of the location.
        > 
      - Location Zip Code #complete
        > The location zip code.
        > 
      - Location Parcel Number #complete
        > The parcel ID.  Uses an API to provide additional details for the location.
        > 
      - Location Jurisdiction #complete
        > The entity which governs the location. 
        > 
      - Location Is Billing Address #complete
        > True of false if the location is used to send invoices.
      - Location Note #complete
        > A note concerning the location.
        > 
      - Location Last Update #complete
        > The date the last update was made for the location.
      - Location Is Not Valid #complete 
        > A Boolean value to not if the location no longer valid.
        > 
    - Entities #complete
      > An entity is a business or agency. 
      > 
      - Entity ID #complete 
        > A unique number for the vendor.
        > 
      - Entity Name #complete 
        > The name of the entity.
        > 
      - Entity Location ID #complete 
        > The location ID
        > 
      - Entity Note #complete 
        > Information about the Entity 
      - Entity Type #complete 
        > Company, Customer, Vendor or Governmental.
        > 
      - Entity Is Not Valid #complete 
        > A Boolean value to not if the entity no longer valid.
        > 
    - Contacts #complete
      > Many contacts for each vendor.
      - Contact ID #complete
        > The unique ID of the contact.
      - Contact Entity ID #complete
        > The ID of the contacts entity.
        > 
      - Contact Salutation #complete
        > Mr. or Ms.
      - Contact First Name #complete 
        > Contact first name
        > 
      - Contact Last Name #complete 
        > The contacts last name.
      - Contact Title #complete 
        > Job title of the contact.
      - Contact Location ID #complete
        > Location Id of the contact
      - Contact Primary Phone #complete
        > Typically the office number. 
      - Contact Primary Phone #complete
        > The extension of the primary number.
      - Contact Secondary Phone #complete
        > Cell phone or other number.
      - Contact Email #complete
        > Email address of the contact.
      - Contact Note #complete
        > Information about the contact.
      - Contact Is Not Valid #complete
        > A Boolean value to not if the contact no longer valid.
        > 
    - Orders #complete
      > Details about the work order
      - Order ID #complete
        > The unique number for the order.
        > 
      - Entity ID #complete
        > The ID of the entity for the order.
        > 
      - Order Contact ID #complete
        > The contact responsible for the order.
      - Order Billing Location ID #complete
        > The location for the invoice.
        > 
      - Order Jobsite Location ID #complete
        > The location of the work.
      - Order Work Order Number #complete
        > The company provided work order number.
      - Order Scope #complete
        > The scope of work being performed.
        > 
      - Order Is Not Valid #complete
        > A Boolean value to not if the order no longer valid.
        > 
    - Application Status Types #complete
      > Different statuses of the application
      > 
      - Application Status Type ID #complete
        > The unique ID of the status type
        > 
      - Design Review #complete
        > Preparing the application.
        > 
      - Quality Review #complete
        > Application submitted and is being check for completeness.
      - Application Review #complete
        > A determination if the application meets the sign code.
      - Corrections Needed #complete
        > The permit has been reviewed and revisions are required.
      - Is Accepted #complete
        > True of false if application has been accepted.
        > 
    - Applications #complete
      > Various fields for the application table.
      > 
      - Application ID #complete 
        > A unique number for each application.
        > 
      - Order ID #complete
        > The is the ID of the order..
      - Application Receipt Timestamp #complete
        > The date the application was received.
        > 
      - Application Description #complete
        > Used to provide important information about the application. 
        > 
      - Has Property Owner Approval #complete
        > True / False if the property owner has approved of the application.
      - Application Submitted Timestamp #complete
        > The date the application was submitted to the judication.
        > 
      - Application Permit Number #complete
        > Provided by the jurisdictional entity.
        > 
      - Application Status ID #complete
        > The ID of the status of the permit.
      - Application Requires Inspection #complete
        > True / false if the application requires an inspection.
      - Application Inspection Note #complete
        > Information about the inspection required.
        > 
      - Application Completion Timestamp #complete
        > The date the application process is complete.
        > 
      - Application Is Not Valid #complete
        > A Boolean value to not if the application no longer valid.
        > 
    - Application Fee Types #complete
      > The different types of permit fees.
      - Application Fee Type ID #complete
        > Unique number for the type of fee.
        > 
      - Review Fee #complete
        > Paid so the permit can be reviewed.
      - Permit Fee #complete
        > The fee for the permit.
      - Amendment Fee #complete
        > A fee paid for reviewing a criteria / plan.
        > 
      - Renewal Fee #complete
        > Fee to reinstate an application.
    - Application Fees #complete
      > Details of the cost of the application.
      > 
      - Application Fee ID #complete
        > A unique number for the fee.
      - Application Note ID #complete
        > The ID for application note for the fee.
        > 
      - Fee Type ID #complete
        > The ID of the fee type.
        > 
      - Fee Timestamp #complete
        > Date of the fee.
      - Fee Amount #complete
        > The amount of the fee.
      - Fee Paid Timestamp #complete
        > The date the fee was paid.
    - Application Correction Types #complete
      > The types of application corrections
      > 
      - Application Correction Type ID #complete
        > The unique ID for the type of correction.
        > 
      - Application Correction #complete
        > Description of the correction.
        > 
    - Application Notes #complete
      > Notes concerning actions taken about the application.
      - Application Note ID #complete
        > The unique ID for the note.
        > 
      - Application Note Action ID #complete
        > Provides when & who made the note
        > 
      - Application Note #complete
        > The application note.
      - Application Note Correction Type ID #complete
        > Noting that revisions to the application are required.
        > 
      - Application Note Fee ID #complete
        > There is a fee associated with the note.
      - Application Note Has File #complete
        > A true / false value if the not has an associated file.
        > 
      - Application Note Is Not Valid #complete
        > A Boolean value to not if the application note no longer valid.
        > 
  - Cron Job #complete
    > The job is creating files on the server that need to be deleted.  Create code that will removing them as they are made.
  - Global Variable Stream  #complete
    > All of the data for the site is placed in this global variable (gblVar).  Some are provided by the database while other a hard coded.  Information from the server (database / text files) is streamed to the client (web browser).  Updates are made and the stream data is changed.
    > 
    - Stream Variables (glbVar.stream) #complete 
      > Details about the stream
      - glbVar.streamCount
        > The stream increment count.
        > 
      - glbVar.streamInterval
        > The amount of time from the current and previous stream.
        > 
      - glbVar.streamSize
        > The count of the number of stream arrays
    - Time / Date Variables (glbVar.timeDate) #complete 
      > The current time & date.
      > 
      - glbVar.currentUnixTime
        > Current unix timestamp
      - glbVar.currentDate
        > This is the current short date.
      - glbVar.currentYearTotalDays
        > Total number of days for the current year.
        > 
      - glbVar.currentYearDayNumber
        > The day number of the current year.
      - glbVar.currentYearDaysRemaining
        > How many days remain in the current year.
      - glbVar.currentMonthNumber
        > The number of the current month (i.e. January - 1)
      - glbVar.currentWeekdayNumber
        > Weekday number (Sunday - 0)
      - glbVar.currentDayNumber
        > The number day in the current month.
      - glb.Timezone
        > What time zone the sever is in
      - glbVar.isWorkday
        > True if the current day is not a weekend or holiday, false otherwise.
      - glbVar.isWeekday
        > True when the current day is between Monday - Friday.
        > 
      - glbVar.isWeekend
        > This is true on Saturday or Sunday; false otherwise.
        > 
      - glbVar.isHoliday
        > True or false depending if the current day is a holiday.
    - Holiday Variables (glbVar.holidays) #complete 
      > Details about the holidays.
      - glbVar.holidayID
        > Unique number for the holiday.
      - glbVar.holidayName
        > Name of the holiday.
        > 
      - glbVar.isHolidayToday
        > True if today is the holiday, false otherwise.
      - glbVar.isNextHoliday
        > If this is the next holiday it is true otherwise false.
        > 
      - glbVar.isPreviousHoliday
        > False unless this was the last holiday, then true.
      - glbVar.holidayDate
        > The unix time stamp (at midnight) fro the holiday
      - glbVar.tillSinceHoliday
        > The time since the date of the holiday date. (negative number means the date has passed)
        > 
    - Intervals Variables (glbVar.eventIntervals) #complete
      > Provides the intervals to various events.
      - Current Interval Type Variables (glbVar.currentInvertalType)
        > Interval that need to be tracked.
        - Is Workday (glbVar.isWorkday)
          > Returns true / false  depending on of the day is worked or not.
          > 
        - Is Workday Type Array (glbVar.isWorkdayType)
          > Periods of the workday.
          > 
          - Is Before Workday (glbVar.isBeforeWorkday)
            > Before the workday begins.
            > 
          - Is During Workday (glbVar.isDuringWorkday)
            > Time when the work day occurs. 
            > 
          - Is After Workday (glbVar.isAfterWorkday)
            > After the work day is completed.
        - Is Weekend (glbVar.isWeekend)
          > If the day is a weekend this is true, otherwise is false.
        - Is Holiday (glbVar.isHoliday)
          > Is true on a holiday.
      - Current Day Variables (glbVar.currentDayDurations) #complete
        > The interval of the current day.
        - Day Spent (glbVar.currentDaySpent)
          > Seconds spent of the days
        - Day Remaining (glbVar.currentDayRemaining)
          > Remaining seconds of the day.
          > 
        - Day Duration (glbVar.currentDayDurationTotal)
          > The total seconds of the day (86400 seconds).
          > 
      - Workday Intervals Variables (glbVar.workdayIntervals) #complete
        > Various intervals used by the website.
        - Worktime Range Variables (glbVar.worktimeRange) #notcomplete
          > Work start / end periods.
          > 
          - glbVar.officeStartTime 
            > Set for 7:30 AM.
            > 
          - glbVar.officeEnd 
            > End time for the office is set for 3:30 PM (15:30 hrs.)
            > 
          - glbVar.shopStartTime
            > The start time for the shop
            > 
          - glbVar.shopEndTime
            > End time for the shop.
            > 
        - Workday Variables (glbVar.workdayIntervals) #complete 
          > The work day intervals.
          - glbVar.lastWorkingDay 
            > The last day of work. 
            > 
          - glbVar.nextWorkingDay
            > The next day to work.
            > 
        - Workday Range (glbVar.workdayRange) #complete
          > The start and end time of the time between workdays.
          - glbVar.intervalStartTime
            > The start / end of the work day depending on the current day.
            > 
          - glbVar.intervalEndTime
            > This also is the start / end of the work day depending on the current day.
            > 
        - Workday Durations Variables (glbVar.workdayDurations) #complete
          > The calculated seconds spent between the Workday Range Intervals.
          > 
          - Workday Spent (glbVar.workdaySpent)
            > Seconds spent of the workday interval
            > 
          - Workday Remaining (glbVar.workdayRemaining)
            > Remaining seconds of the workday interval.
            > 
          - Workday Duration (glbVar.workdayDurationTotal)
            > The total seconds of the interval (depends on workday range).
            > 
      - Holiday Variables (glbVar.holidayIntervals) #notcomplete 
        > Interval of elapse & remain time for holidays.
        > 
        - Previous Holiday Variable (glbVar.previousHoliday) #complete
          > Time elapsed during the holiday.
          > 
          - glbVar.previousHoliday
            > The name of the holiday.
            > 
          - glbVar.previousHolidayDate
            > Unix timestamp of the day (midnight)
            > 
          - glbVar.previousHolidaySince
            > How long ago (in seconds) from the current time.
            > 
        - Next Holiday Variable (glbVar.nextHoliday) #complete
          > Time till the next holiday.
          > 
          - glbVar.nextHoliday
            > Name of the holiday.
            > 
          - glbVar.nextHolidayDate
            > Unix timestamp when midnight occurs on the holiday.
            > 
          - glbVar.previousHolidaySince
            > Number of seconds till the holiday
            > 
        - Holiday Durations Variable (glbVar.holidayDurations) #complete
          > The amount of time (seconds) between the holidays
          > 
          - glbVar.holidaySpent
            > Time since the last holiday has passed.
          - glbVar.holidayRemaining
            > Remaining time till the holiday.
            > 
          - glbVar.holidayDurationTotal
            > Total seconds between the holidays.
    - Site Details Variables (glbVar.siteDetails) #complete
      > Details about the website.
      > 
      - Site Name Variables (glbVar.siteName) #complete
        > Names used for the site.
        - glbVar.siteName
          > Name of the site.
        - glbVar.pageTitle
          > The page title.
          > 
        - glbVar.pageHeader
          > Initial page header.
          > 
        - glbVar.standardPageHeader
          > The standard page header.
          > 
        - glbVar.siteBeginDate
          > The date the site began.
      - Site Cron Count Variables (glbVar.siteCronCount) #complete
        > Cron count information. 
        - glbVar.siteCronCount
        - glbVar.siteLastCronJob
        - glbVar.siteNextCronJob
        - glbVar.timeTillNewCronJob
        - glbVar.timeSinceLastCronJob
      - Site Testing Variables (glbVar.siteFunctions) #complete
        > Site testing details
        - glbVar.siteErrorsCount
          > The number of PHP errors for the code
        - glbVar.siteTestingStatus
          > True if the site is being testing, otherwise false.
          > 
        - glbVar.siteIPAddress
          > The IP address.
          > 
        - siteDatabaseTextFilesSize
          > The size of both the static and updatable database text files (in bytes).
          > 
        - Site Functions Variables (glbVar.siteFunctions) #complete 
          > The use of PHP code functions.
          - glbVar.functionID
            > A unique  number for the function.
          - glbVar.functionName
            > The name of the function.
          - glbVar.functionUseCount
            > Number of times it has been called
          - glbVar.functionFirstUse
            > The first time it was called.
          - glbVar.functionLastUse
            > Last time the function was used.
          - glbVar.timeSinceLastFunctionUse
            > Seconds since the last use.
            > 
          - glbVar.functionSessionRequired
            > True / false if a session if required to use the function.
            > 
    - User Details Variables (glbVar.userDetails) #complete
      > User details.
      - glbVar.userID
        > The unique contact ID for the user.
      - glbVar.userFirstName
        > First name of the user.
      - glbVar.userLastName
        > The users last name.
        > 
      - glbVar.rememberMe
        > Check if the user has unable cookies (0-False, 1-True).
      - glbVar.userEmailAddress
        > The unique name the user has provided.
        > 
      - glbVar.passwordConfirmed
        > Noting the the password has been confirmed (0-False, 1-True).
      - glbVar.sessionStatus
        > Default Set To False (0-False, 1-True).
      - User Last Action Variables (glbVar.userLastAction) #complete
        > Details about the last action performed by the user.
        > 
        - glbVar.userLastActionID
          > ID of the users last action.
          > 
        - glbVar.userLastActionTime
          > Time of the last action.
        - glbVar.userLastActionTypeID
          > The ID of the last action taken.
          > 
        - glbVar.userLastActionContactID
          > The action type and name makes up the description.
          > 
        - glbVar.userLastActionLatitude
          > Action Latitude
        - glbVar.userLastActionLongitude
          > The action longitude.
          > 
      - Idle Variables (glbVar.userIdleVariables) #complete 
        > Variables used for the idle user log out. 
        > 
        - glbVar.idleLogoutInterval
          > Set to 900 seconds (15 minutes).
          > 
        - glbVar.idleLogoutSpent
          > The increment count to the logout.
        - glbVar.idleLogoutRemaining
          > Seconds till idle logout
          > 
        - glbVar.idleLogoutTime
          > What time the user will be idle logged out.
          > 
        - glbVar.userIdleStatus
          > False when action is taken or detected (true if idle).
          > 
    - Database Count Variables (glbVar.databaseCount) #complete
      > Counts of the database tables.
      > 
      - glbVar.actionsCount
        > Used to determine if a new action has taken place.
        > 
        - glbVar.currentActionsCount
          > The number of actions counted in the database.
          > 
        - glbVar.previousActionsCount
          > A count of the number of actions from the previous stream.
        - glbVar.newActionAdded
          > True if there is a difference between the counts, false otherwise.
          > 
      - glbVar.entitiesCount
        > A count of all entity records.
        - glbVar.currentEntitiesCount
          > The number of entities counted in the database.
          > 
        - glbVar.previousEntitiesCount
          > A count of the number of entities from the previous stream.
        - glbVar.newEntityAdded
          > True if there is a difference between the counts, false otherwise.
          > 
      - glbVar.locationsCount
        > The number of locations in the database.
        - glbVar.currentLocationsCount
          > The number of locations counted in the database.
          > 
        - glbVar.previousLocationsCount
          > A count of the number of locations from the previous stream.
        - glbVar.newLocationAdded
          > True if there is a difference between the counts, false otherwise.
          > 
      - glbVar.contactsCount
        > How many contacts there are.
        > 
        - glbVar.currentContactsCount
          > The number of contact counted in the database.
          > 
        - glbVar.previousContactsCount
          > A count of the number of contacts from the previous stream.
        - glbVar.newContactAdded
          > True if there is a difference between the counts, false otherwise.
          > 
      - glbVar.ordersCount
        > The number of orders.
        - glbVar.currentOrdersCount
          > The number of order counted in the database.
          > 
        - glbVar.previousOrdersCount
          > A count of the number of orders from the previous stream.
        - glbVar.newOrderAdded
          > True if there is a difference between the counts, false otherwise.
          > 
      - glbVar.applicationsCounts
        > This is how many application records there are.
        > 
        - glbVar.currentApplicationsCount
          > The number of applications counted in the database.
          > 
        - glbVar.previousApplicationsCount
          > A count of the number of applications from the previous stream.
        - glbVar.newApplicationAdded
          > True if there is a difference between the counts, false otherwise.
          > 
      - glbVar.applicationFeesCount
        > The count of fees for the applications.
        - glbVar.currentApplicationFeesCount
          > The number of application fees counted in the database.
          > 
        - glbVar.previousApplicationFeesCount
          > A count of the number of application fees from the previous stream.
          > 
        - glbVar.newApplicationFeeAdded
          > True if there is a difference between the counts, false otherwise.
          > 
      - glbVar.applicationNotesCount
        > A count of the notes made on the applications.
        - glbVar.currentApplicationNotesCount
          > The number of application note counted in the database.
          > 
        - glbVar.previousApplicationNotesCount
          > A count of the number of application notes from the previous stream.
          > 
        - glbVar.newApplicationNoteAdded
          > True if there is a difference between the counts, false otherwise.
          > 
    - Updatable Database Variables (glbVar.updatableDatabase) #complete
      > The data that populates the datatables.
      > 
      - glbVar.actions
        > List of actions taken by users on the site.
        > 
        - glbVar.actionID
          > Unique number for the action.
          > 
        - glbVar.actionTimestamp
          > Unix time when the action occured.
        - glbVar.actionTypeID
          > The value of the action from the actions type table.
          > 
        - glbVar.actionContactID
          > The ID of the user that performed the action.
        - glbVar.actionLatitude
          > Latitude of the action.
        - glbVar.actionLongitude
          > The longitude of the action.
      - glbVar.entities
        > Companies, vendors, jurisdictions & customers. 
        > 
        - glbVar.entityID
          > Unique ID for the entity.
        - glbVar.entityName
          > The name of the entity.
        - glbVar.entityLocationID
          > Location ID for the entity.
          > 
        - glbVar.entityNote
          > A note about the entity.
          > 
        - glbVar.entityType
          > ID of the entity type.
        - glbVar.entityIsNotValid
          > True if the entity is not valid, false otherwise.
          > 
      - glbVar.contacts
        > Contacts of the entities.
        - glbVar.contactID
          > Unique ID for the contact.
          > 
        - glbVar.contactEntityID
          > The ID of the entity for the contact.
        - glbVar.contactSalutation
          > Salutation of the contact.
          > 
        - glbVar.contactFirstName
          > Contact first name.
          > 
        - glbVar.contactLastName
          > Last name of the contact. 
        - glbVar.contactTitle
          > Title of the contact,
        - glbVar.contactLocationID
          > Location ID for the contact.
          > 
        - glbVar.contactPrimaryPhone
          > Primary phone number.
          > 
        - glbVar.contactPrimaryPhoneExtension
          > Extension of the primary phone.
          > 
        - glbVar.contactSecondaryPhone
          > Secondary phone number.
          > 
        - glbVar.contactEmail
          > Email for the contact.
        - glbVar.contactNote
          > Contact note,
        - glbVar.contactIsNotValid
          > True if the contact is not valid, false otherwise.
      - glbVar.locations
        > Entity locations
        - glbVar.locationID
          > Unique ID for the location.
          > 
        - glbVar.locationName
          > Name of the location.
        - glbVar.locationPlaceID
          > Google place ID.
        - glbVar.locationLatitude
          > The latitude of the location.
        - glbVar.locationLongitude
          > Longitude of the location.
        - glbVar.locationAddress
          > Street address of the location.
        - glbVar.locationAddressSuite
          > Suite for the location.
        - glbVar.locationCity
          > City of the location.
        - glbVar.locationState
          > Location state
          > 
        - glbVar.locationZipCode
          > The zip code for the location.
          > 
        - glbVar.locationParcelNumber
          > Parcel number for the location.
          > 
        - glbVar.locationJurisdiction
          > Location jurisdiction.
        - glbVar.locationIsBillingAddress
          > Is location a billing address.
          > 
        - glbVar.locationNote
          > Note for the location.
        - glbVar.locationLastUpdated
          > Last update for the location.
        - glbVar.locationIsNotValid
          > This is true if the location is not valid, falsr otherwise.
      - glbVar.orders
        > Orders for customers
        - glbVar.orderID
          > The unique ID for the order.
          > 
        - glbVar.entityID
          > Entity ID for the order,
        - glbVar.orderContactID
          > Contact ID for the order.
        - glbVar.orderBillingLocationID
          > Billing location ID for the order.
          > 
        - glbVar.orderJobsiteLocationID
          > The jobsite location ID for the order.
          > 
        - glbVar.orderWorkOrderNumber
          > Work order number.
          > 
        - glbVar.orderScope
          > Scope of work for the order.
          > 
        - glbVar.orderIsNotValid
          > True is the order is not valid, false otherwise.
      - glbVar.applications
        > The sign permit applications for orders.
        > 
        - glbVar.applicationID
          > Unique ID for the application.
        - glbVar.orderID
          > Order ID for the application.
        - glbVar.applicationReceiptTimestamp
          > Date (timestamp) when the application was received.
          > 
        - glbVar.applicationDescription
          > Description of the application.
        - glbVar.hasPropertyOwnerApproval
          > True / False if the property owner approval has been received.
          > 
        - glbVar.applicationSubmittedTimestamp
          > When the application was submitted to the jurisdiction (timestamp).
          > 
        - glbVar.applicationPermitNumber
          > The permit application number.
          > 
        - glbVar.applicationStatusID
          > Application status ID.
        - glbVar.applicationRequiresInspection
          > True / False if the application requires an inspection.
          > 
        - glbVar.applicationInspectionNote
          > Note for the application.
          > 
        - glbVar.applicationCompletionTimestamp
          > The date the application was completed.
        - glbVar.applicationIsNotValid
          > This is true if the application is not valid, false otherwise.
          > 
      - glbVar.applicationFees
        > Fees for the applications
        - glbVar.applicationFeeID
          > Unique fee for the application fee.
        - glbVar.applicationNoteID
          > Note ID for the application note.
        - glbVar.feeTypeID
          > Fee type ID
        - glbVar.feeTimestamp
          > The fee timestamp.
        - glbVar.feeAmount
          > Amount of the fee,
        - glbVar.feePaidTimestamp
          > Date (timestamp) the the fee was paid.
      - glbVar.applicationNotes
        > Notes made concerning the applications.
        - glbVar.applicationNoteID
          > Unique ID for the application.
          > 
        - glbVar.applicationNoteActionID
          > Note action ID for the note.
        - glbVar.applicationNote
          > The application note.
        - glbVar.applicationNoteCorrectionTypeID
          > Application note correction type ID
        - glbVar.applicationNoteFeeID
          > The application not fee ID.
          > 
        - glbVar.applicationNoteHasFile
          > True / False if the note has a file.
          > 
        - glbVar.applicationNoteIsNotValid
          > True if the not is not valid, false otherwise,
          > 
    - Static Database Variables (glbVar.staticDatabase) #complete
      > Data from the database which doesn't require frequent updates.
      > 
      - glbVar.actionTypes
        > The actions a user can perform on the website.
        - glbVar.actionTypeID
          > Unique number for the action
        - glbVar.actionType
          > The CRUD type of the action (Create, Read, Update or Delete)
          > 
        - glbVar.actionName
          > The name that describes the action.
          > 
      - glbVar.applicationCorrectionTypes
        > Categories of the application corrections.
        > 
        - glbVar.applicationCorrectionTypeID
          > The unique number for the correction type
          > 
        - glbVar.applicationCorrectionType
          > The type of correction.
          > 
        - glbVar.applicationCorrectionDescription
          > A description of the correction.
      - glbVar.applicationFeeTypes
        > Types of fees for permit applications.
        - applicationFeeTypeID
          > Unique number for the fee type.
        - applicationFeeType
          > Description of the fee.
      - glbVar.applicationStatus
        > Used to assign the current status of an application.
        - glbVar.applicationStatusTypeID
          > Unique number for the status type.
          > 
        - glbVar.applicationStatus
          > Description of the application status.
    - Dashboard Variables (glbVar.liveDashboard) #complete
      > Provides key performance indicators and other relevant data in real-time.
      > 
      - glbVar.liveDashboardIntervals #complete
        > The current status of the progression of certain time periods.
        - Current Day #complete 
          > The length of the current day.
          > 
        - Workday #complete 
          > What portion of the workday is and its duration.
          > 
        - Holiday #complete 
          > When & how long till the next holiday.
          > 
      - glbVar.liveDashboardAlerts #complete 
        > Key Performance Indicators (KPI) which showcase operational metrics.
        > 
        - Current CRUD Action
          > Notice that an action has taken place.
          > 
        - Last CRUD Action
          > Refers to the last action performed.
          > 
        - Applications Total Count
          > Number of applications entered.
        - Current Applications Count
          > Count of the applications current open.
          > 
        - Average Turn-Around Time
          > The overall average turn around of applications.
          > 
  - Chat GPT #notcomplete 
    > API to query database and text files.  Upload sign permit codes so they can be queried.
    > 
- Steps #notcomplete
  > Actions to be taken to create the site.
  - Login Cookie #notcomplete
    > Set up the cookie to recognize a user and provide the username in the login input.
    - User Cookie Check
      > Determine if a cookie is present.  This return true or false.
      > 
    - User Cookie
      > The value is to be the users contact ID number rather than the name.
      > 
    - Cookie Ajax Call
      > Must obtain the user ID if the cookie is present.
    - Login
      > Creates a cookie when the user logs in.  The 'userCookie' is to be the contact ID.
      > 
  - Site Errors #notcomplete
    > Update the server side errors and make them part of the stream.
    > 
  - Call Create New Action Function #complete
    > Review the code to call a new action & test that the SSE updates the text files with a new array value.
  - Global Variable Stream #notcomplete 
    > Obtain the counts of the saved arrays & compare them to the database table counts.  Update the saved array if there is a count difference.  Use the saved arrays to populate the website.
    > 
  - Login / Logout  #notcomplete 
    > Set up the site for a user to login / logout.  Update the database records when this is performed.
    - Session Variables #notcomplete 
      > Review how the site will use session variables to determine the user and provide permissions to the site.
      > 
    - Database Records #notcomplete
      > Update the database as the user logs in/out.
      > 
    - Website Changes #notcomplete 
      > Show / Hide portions of the site based on the users login status.
      > 
    - Idle Logout #notcomplete
      > Set up the cron job to log a user out after 15 minutes of inactivity.
      > 
  - Update Site Arrays #notcomplete
    > New array values are to be made on the Updateable Database Array text file as the database records are made.
    > 
    - Actions Count #notcomplete 
      > Update the action array when there is a difference between previous & current action counts.
      > 
    - Action Types #notcomplete
      > Update arrays based on the action name & type of action that has taken place.
      - Create #notcomplete
        > The following are actions that create a new record in the database and require an update in the text file array.
        - 03 - Create Entity
          > Updates the entity array.
          > 
        - 04 - Create Location
          > Location array is updated.
        - 05 - Create Contact
          > Contacts has a new record.
        - 06 - Create Order
          > A new record was created in the order table.
          > 
        - 07 - Create Application
          > The application array has a newly created record.
        - 08 - Create Application Note
          > Make a new item in the application notes array.
          > 
        - 09 - Create Application Fee
          > An application fee has been added to the table record.
          > 
      - Update #notcomplete
        > These are update actions which make changes to the database records.
        > 
        - 19 - Update Entity #notcomplete
          > The entity record has been updated.
          > 
        - 20 - Update Location #notcomplete 
          > A location has new information.
          > 
        - 21 - Update Contact #notcomplete
          > The array for the contact is to be updated with a change in the records.
          > 
        - 22 - Update Application #notcomplete
          > An application is updated.
          > 
        - 23 - Update Application Fee #notcomplete
          > A record in the application fee table has been changed. 
          > 
        - 24 - Update Application Status #notcomplete 
          > Status of an application has changed in the database.
        - 25 - Update Application Note #notcomplete
          > An application note change requires the array be updated.
          > 
      - Delete #notcomplete
        > These are invalidations of database records which require an update in the text files.
        > 
        - 26 - Delete Entity #notcomplete
          > The entity record has been deleted.
          > 
        - 27 - Delete Location #notcomplete 
          > A location is no longer valid.
          > 
        - 28 - Delete Contact #notcomplete
          > The contacts array has a records that has been invalidated.
          > 
        - 29 - Delete Application #notcomplete
          > An application is now invalid.
          > 
        - 30 - Delete Application Fee #notcomplete
          > A record in the application fee table has been deleted. 
          > 
        - 31 - Delete Application Status #notcomplete 
          > Status of an application has changed to invalid in the database.
        - 32 - Delete Application Note #notcomplete
          > An application note change requires the array noted as not valid.
          > 
  - Cron Job #notcomplete
    > Review the code that is called with the cron job.
    > 
    - Idle Logout #notcomplete
      > Used to perform the logout function of a user that hasn't performed an action for 15 minutes.
      > 
    - Update Users Task #notcomplete
      > All tasks for the day are to be reset a midnight.
    - Backup Files / Database #notcomplete
      > Copies of the files and database to be made on the server and remotely. 
      > 
  - Site Errors #notcomplete
    > Obtaining the current errors & updating the array of total errors.
    > 
    - Current Errors
- Code Formatting Guidelines
  > Clean, Readable, and Maintainable Codebase. By adhering to these guidelines, we not only aim to elevate the quality of our code but also ensure that it stands as a testament to our dedication to craftsmanship. Our commitment is to foster a development environment where clarity, efficiency, and maintainability are paramount. These practices are not just rules but principles that guide us toward excellence in every line of code we write. Embracing these standards is our collective step towards building software that is not only robust and efficient but also a joy to work with for every member of our team.
  - Variable Naming Conventions
    > Use camelCase and prefix variables with var for clarity and scope definition, ensuring they are easily recognizable and logically grouped.
    - CamelCase Usage
      > Adopt camelCase for variable names (e.g., userProfile) to enhance readability and differentiate between variables, functions, or constants.
    - Var Prefix
      > Begin variable declarations with var to define scope explicitly and prevent unintended global variables (e.g., var itemCount).
  - Commenting Strategy
    > Maintain concise and descriptive comments, with title-cased statements above the code lines for quick understanding. Provide additional details next to the code for context, and align complex explanations to the right, creating clear code narration.
    - Above-Line Comments
      > Place a succinct, title-cased comment above each line of code, focusing on the action or purpose (e.g., // Initialize Array).
    - Parenthetical Clarifications
      > Use parentheses for additional context or nuances next to comments (e.g., // Initialize array (empty if no data)).
    - Right-Aligned Explanations
      > For detailed explanations, align comments to the right of the code, ensuring the primary focus remains on the code itself.
    - Detailed Inline Comments
      > Include comments for complex operations or variable initializations, explaining their purpose succinctly and directly above the code.
  - Conditional Statements
    > Clearly label conditionals with comments to outline logic flow and maintain code continuity.
    - Initial Above-Line Comment
      > Start each conditional block with a concise comment that ends with 'Conditional' for immediate context.
    - Clear Labeling
      > Provide straightforward comments before conditionals to make the code's flow easily understandable.
  - Explicit Loop Descriptions
    > Describe loops' purposes before they begin, stating what is being iterated over and why.
  - Return Statement Precision
    > Ensure return statements are direct and formatted without superfluous spaces, clearly grouping all related properties.
  - Setting Attributes
    > Detail HTML attributes to enhance code clarity and understanding, promoting a uniform and accessible codebase.
    - Comment Every Attribute
      > Add a comment before each attribute assignment describing its purpose, starting with 'Assign' for setting a value and 'Initialize' for placeholders.
    - Clarify Purpose and Value
      > Clarify the source or purpose when setting values, especially for dynamic ones (e.g., // Assign Current Contact Primary Phone).
    - Maintain Readability
      > Align attribute settings and their comments to improve readability and comprehension.
    - Consistent Commenting Style
      > Adopt a consistent style for attribute comments across the codebase for uniformity.
      > 
  - Overall Structure
    > Structure code blocks with clear, introductory comments and consistent indentation and spacing for better navigability and readability.
    - Block Introduction
      > Precede significant code blocks with a clear, concise comment outlining their purpose, aiding navigation and comprehension.
    - Avoid Empty Lines
      > Eliminate unnecessary space between related code blocks to maintain logical flow.
    - Consistent Indentation and Spacing
      > Use uniform indentation and spacing to make the code more accessible and easier to read.
    - Dynamic Content Handling
      > Provide guidelines for handling dynamic content, especially with global variables or data streams, to ensure data integrity and responsive updates.
    - Error Handling and Logging
      > :Gracefully implement error handling and logging within the code to effectively manage and debug issues.
- Other Stuff #notcomplete
  > Things I might not need
  - Code Functions #notcomplete
    > Details about the code that are saved to track their utility.
    > 
    - Function Usage Steps #notcomplete
      > The manner in which a function use is accounted for.
      > 
      - Function Authorization Check
      - Block Unauthorized IP Addresses
      - Update Function Unauthorized Use
      - Update Function Usage
      - Check If Function Exists
      - Create New Function
      - Obtain Function Usage Count
      - Update Function Count
      - Obtain Session Authorization Status
    - Function Usage Count #notcomplete
      > A list of all the functions & their usage.
      > 
      - Function Name #notcomplete
        > The name of the function.
      - Function Usage Count #notcomplete
        > Number of times it was called.
        > 
      - Function Last Use #notcomplete 
        > A unix timestamp of when it was last used.
        > 
  - Folders/Files #notcomplete
    > Create the folders & files that are to run the site.
    > 
    - Index Page #notcomplete 
      > Used to securely login to the site.
      > 
    - Server Pages
      > PHP pages used by the site.
      > 
      - General Page #notcomplete 
        > Used for basic server side functions.
      - Cron Job Page #notcomplete
        > Updates the database & performing functions every minute.
        > 
      - Federal Holiday #notcomplete
        > Used to account for the holidays.
        > 
  - Landing Page #notcomplete
    > Use the same landing page & login structure as before.
    > 
  - Grids #notcomplete
    > The data is to be presented on the website using DataTables.  See [https://datatables.net/](https://datatables.net/) to determine which bootstrap style will be used.
    > 
    - Events #notcomplete 
    - Entities #notcomplete
    - Contacts #notcomplete
    - Locations #notcomplete 
    - Orders #notcomplete
    - Applications #notcomplete 
  - Model Pages #notcomplete
    > Set up pages with tabs and controls.
  - Alerts #notcomplete
    > Provide updates using server side events.
    > 
  - Dynamic Code #notcomplete
    > Make code to update the databse from the pages using server side events.
  - Read Pages #notcomplete
    > The pages are to read the database & display the data.
  - Reports #notcomplete
    > Provide reports available from selecting menu.
    > 
    - Current Status
    - Fees Due
    - Weekly Report
    - Permit Timeline
  - Audio #notcomplete
    > Review the audio files and create their CRUD functionality.  Use this in links, buttons and other controls to signify what action took place.
    > 
- Current Stuff #inprogress
  > Things being worked on
  - File Naming Conventions #complete 
    > The files for the reports are renamed when saved on the server. (i.e. 00001_00_01_001_00001_1626452135.jpg)  The sample of the components of the 36 characters that make up the file name.  This includes the underscores and file extension
    > 
    - Order ID
      > A 5-digit padded number representing the order ID. (e.g., 00001)
    - Report Type
      > A two-digit code representing the type of report (e.g., 00 - survey, 01 - completion).
      > 
    - Report Instance Number
      > A two-digit padded number representing the instance number of the report.(e.g., 01)
    - File Index
      > A three-digit padded number representing the index of the file within the report instance. (e.g., 001)
    - User ID
      > A 5-digit padded number representing the user ID who uploaded the file. (e.g., 00001)
    - Timestamp:
      > A 10-digit Unix timestamp representing the time of the upload. (e.g., 1626452135)
    - Underscores
      >  6 underscores (_)
    - File Extension
      > 3 characters for common extensions like jpg,  Only .jpg .png .gif & .pdf files are to be used.
      > 
  - Code Backup #notcomplete
    > We have code set up to back up the site.  Currently the database is not being backed up.
    - Database Backup #complete
      > Determine why the database is not being backed up and correct the issue.
    - Dropbox #notcomplete
      > We want to place the updates on Dropbox via the API however it is not working.
      > 
  - Order Notes #complete 
    > We restarted the database text files and now the notes are missing.  We can not create a new one.
    > 
  - Photo Report Summaries #inprogress
    > The summary of a report will be store in a global variables.
    > 
    - Global Variable Setup #inprogress 
      > Review the current code and determine how it is set up.  Make changes to place it in working order.
    - Summary Editing #notcomplete
      > The summary will need to be updatable.
      > 
  - Report Summaries #notcomplete
    > Prepare the AI summaries for the reports so they are more accurate & pithy.
  - Applications #notcomplete
    > Set up the applications grid like the orders.
  - Reports #notcomplete
    > The purpose of collecting and storing photos and files on the server is to centralize data collection, ensuring a universal approach that facilitates the creation of comprehensive reports. These reports will provide detailed information about each order, enabling users to review progress and make informed decisions on how to proceed with order completion.
    > 
    - Photo Reports #notcomplete 
      > Types of photo reports
      - Survey Photos
        > Initial assessment images of the site.
      - WIP (Work in Progress) Photos
        > Images showing the ongoing progress of the work.
      - Completion Photos
        > Final images taken upon the completion of the work.
      - Other Photos
        > Any additional images that might be relevant to the order.
    - File Reports #notcomplete 
      > Type of file reports
      > 
      - Customer Documents
        > Important documents provided by the customer.
      - Other Documents
        > Additional documents that are pertinent to the order.
    - Summary #notcomplete 
      > To achieve this, we will store the photos and files in folders on the server. Each order will have a dedicated folder named after the order number, which is the order's primary key. Within this folder, there will be subfolders to store items for the various reports. This structure ensures that the reports are comprehensive and easily accessible for order management and decision-making processes.
  - Electronic Bulletin Boards #notcomplete
    > The electronic bulletin boards are strategically placed monitors across different areas of our office to enhance communication and keep everyone updated on vital information. Each monitor will display tailored content relevant to its location, ensuring that all departments have real-time access to the information they need most.
    - Monitor Placements and Their Specific Displays
      > We will have the monitors in various places throughout the facility for different departments.
      - Reception Area
        > Front office entrance
        - Purpose
          > To create a welcoming and informative environment for visitors and clients.
        - Content Displayed
          > What will be displayed.
          - Company Information
            > Details about the company, including our mission, values, and services.
          - Project Showcasing
            > Photos and brief descriptions of recently completed projects to showcase our work and capabilities.
          - Customer Testimonials
            > Highlighting successful projects.
          - General Information
            > Business hours, contact details, and announcements
      - Office Area
        > Central workspace for the office team.
        - Purpose
          > To keep the office team updated on ongoing projects and sales activities, fostering awareness and collaboration.
        - Content Displayed
          > Information relevant to the office staff.
          - Work in Progress
            > Status of work in progress, including key milestones and deadlines for various projects.
          - Permit Status
            > Real-time updates on permit statuses to ensure regulatory compliance.
          - Sales Data
            > Sales data and updates, including targets, achievements, and upcoming opportunities.
          - Company Announcements
            > Relevant updates for all office staff.
      - Design Department
        > Design team area.
        - Purpose
          > To streamline design workflow and prioritize requests.
        - Content Displayed
          > Design-related information.
          - Design Requests
            > A list of design requests, including deadlines, assigned designers, and current progress.
          - Project Status
            > Status of ongoing design projects, including feedback loops and revision requests.
          - Inspirations & Tips
            > Tips, inspirations, and internal resources to foster creativity and efficiency.
      - Service Department
        > Service team workspace.
        - Purpose
          > To ensure timely and accurate service delivery by keeping the team informed about their current workload.
        - Content Displayed
          > Service-related information.
        - Service Orders
          > A list of service orders, their current status, and any upcoming deadlines.
        - Team Assignments
          > Service team assignments and schedules to help manage time and resources effectively.
        - Urgent Alerts
          > Alerts for urgent service requests or changes in client priorities.
      - Production Area
        > Fabrication and production workspace.
        - Purpose
          > To keep production staff updated on work orders and streamline fabrication processes
        - Content Displayed
          > Production-related information.
          - Work Orders
            > A list of work orders assigned for fabrication, including details such as materials required, deadlines, and production stage.
          - Order Changes
            > Updates on any changes to work orders, such as revisions or cancellations.
          - Safety Guidelines
            > Safety reminders and guidelines to ensure a safe working environment.
    - Benefits of Electronic Bulletin Boards
      > Advantages of implementing electronic bulletin boards.
      - Key Performance Indicators (KPIs)
        > Display the company’s KPIs to provide transparency on performance metrics and promote a results-driven culture.
        - Purpose of Displaying KPIs
          > The value of placing these on the bulletin boards.
          > 
          - Company’s Performance
            > To keep all employees informed about the company’s performance against strategic goals and objectives.
          - Accountability & Improvement
            > To foster a culture of accountability and continuous improvement by making performance metrics visible to everyone.
        - Examples of KPIs to Display
          > Things to consider as Key Performance Indicators,
          - Sales Targets vs. Achievements
            > Show sales goals and progress towards them, updated in real-time.
          - Production Efficiency
            > Display metrics on production speed, quality control, and output rates.
          - Customer Satisfaction Scores
            > Include feedback and satisfaction metrics from customers to highlight service quality.
          - Project Timelines and Deadlines
            > Visualize upcoming project deadlines and status updates to ensure timely completion.
          - Financial Metrics
            > Show key financial indicators like revenue growth, cost savings, and profit margins.
      - Increased Transparency
        > By displaying real-time information, everyone stays informed about what other departments are working on, leading to better collaboration and understanding.
      - Enhanced Productivity
        > Easy access to current tasks, deadlines, and updates helps employees prioritize their work more effectively and reduces the need for frequent meetings or check-ins.
      - Improved Communication
        > The bulletin boards serve as a centralized information source, reducing information silos and ensuring all team members have access to the latest updates.
      - Boosted Morale and Engagement
        > Regular updates, countdowns to holidays, and showcasing achievements help maintain a positive and engaged workplace environment.
    - Main Card and Minor Cards Concept
      > An essential component of each Electronic Bulletin Board (BB) is the use of "main cards" and "minor cards" to display the most relevant information for each department. These cards are designed to keep employees informed with up-to-date and essential data tailored to their specific work environment.
      - Main Card
        > The main card is the central focus on each BB, showing the most critical information specific to the department or location. Typically formatted as a list, it highlights key data points such as dates, counts, and Key Performance Indicators (KPIs).
        - Purpose
          > To provide an at-a-glance view of the most important tasks, deadlines, or metrics relevant to each department. This includes showcasing KPIs that drive performance and transparency within the company.
        - Content Displayed
          > Main card content
          - Key Performance Indicators (KPIs)
            > Metrics such as sales targets, project completion rates, or production efficiency to track departmental performance.
          - Dates
            > Upcoming deadlines or scheduled tasks to keep teams aware of time-sensitive responsibilities.
          - Counts
            > Quantities such as the number of units produced or tasks completed to give a quick snapshot of ongoing activities.
      - Minor Cards
        > Minor cards provide additional context and supplementary data to support the main card’s information. These cards rotate periodically to keep the content fresh and engaging, offering deeper insights into specific areas of interest.
        - Purpose
          > To offer more detailed information and context that enhances the understanding of the main card’s data. This helps employees grasp a fuller picture of departmental performance and operational priorities.
        - Content Displayed
          > Minor card details
          - Additional Metrics
            > Information like turnaround times, daily totals, or detailed counts to provide a more comprehensive view.
          - Supplementary Data
            > Details such as recent achievements, specific challenges, or highlights from ongoing projects.
          - Rotating Content
            > Periodically updated information to maintain viewer engagement and ensure all relevant details are covered.
      - Standard Cards
        > General content that will be visible on all monitors regardless of location.
        - Workday Hours Left
          > A countdown to the end of the workday to help staff manage their time effectively.
        - Time to Next Holiday
          > A countdown to the next company holiday to keep morale high and help with planning.
        - Weather Forecast
          > A daily weather update to assist with planning, particularly for those working outside or managing deliveries.
        - Showcase of Completed Work
          > A rotating gallery of recently completed projects across all departments to celebrate achievements and encourage cross-departmental awareness.
    - Alert Messages
      > The use of alerts to improve engagement and communication through your electronic bulletin boards
      - Current Interval: Hourly Notice
        > Display a notification on the screen reminding users of the time remaining in the current work interval.
        - Header
          > "Current Interval Update"
          > 
        - Content
          > "There are XX minutes remaining in this work interval."
        - Visual
          > A subtle transition effect, possibly a fade-in with a small confetti effect, or another smooth visual cue.
        - Sound
          > A soft chime that isn’t too disruptive but grabs attention.
      - Breaking News: Milestones & Key Actions
        > This would be used when significant actions occur, such as an initial login, a sold job, an order completed.  Performance milestones (e.g., record-breaking monthly sales).
        > 
        - Header
          >  "Breaking News"
        - Content
          > The specific event, such as “New Sale! Order XYZ has been completed."
          > 
        - Visual
          > More intense than the Current Interval notice. It could include a more noticeable splash on the screen with larger confetti, dynamic color changes, or some bold animation.
        - Sound
          > A celebratory sound (a “breaking news” type audio alert) that plays once for impact.
      - Celebrations: Birthdays & Holidays
        > Celebrate key moments (birthdays, holidays) at half-hour intervals throughout the day.
        - Header
          > "Celebrate!"
        - Content
          > “Happy Birthday to [Employee Name]!” or “Happy [Holiday Name]!”
        - Visuals
          > Colorful confetti and maybe balloons or other celebratory animations.
        - Sounds
          >  A festive sound, possibly a short jingle, to accompany the visual.
    - Implementation Plan
      > Steps to set up the electronic bulletin boards.
      - Assess Monitor Placement
        > Identify the best locations for each department to place the monitors, ensuring high visibility without disrupting workflow.
      - Content Management System (CMS)
        > Develop or integrate a CMS that allows easy updates and management of the content displayed on each monitor.
      - Set Up and Test
        > Install the monitors and test the content display for functionality and visibility.
      - Continuous Improvement
        > Gather feedback from each department to refine the content and presentation continuously.
  - Contact Entries #notcomplete 
    > Using a text field to enter raw contact data and have the database updated with proper contacts.
    > 
    - Contact Entry Types #notcomplete
      > These are the different types of entrances that the code is set up to make.
      > 
      - Unacceptable Data
        > This indicates that there was a conflict in the data provided that could not be automatically resolved. It is used when there is a mismatch between user-provided data and API-verified data, or other critical issues that prevent processing.  Any status (entity, contact, location) marked as `0` is considered unacceptable due to incomplete or flawed data.
        >    - In the case of a duplicate, while the data may be verified, it is flagged as unacceptable (`contactStatus = 0`) due to being non-actionable.
        - Missing Data
          > Critical fields necessary for creating or updating entries are incomplete. Criteria:
          - Contact Information
            > Missing firstName, lastName, or both email and primaryPhone (at least one is required).
          - Entity Information
            > Missing entityName.
          - Location Information
            >  Missing locationAddress, locationCity, locationState, or locationZipCode.  Set proposedContactStatus to 0 and flag as "Missing Data". Add proposedContactNotes to specify which fields are missing (e.g., "Missing critical fields: firstName, locationAddress").
        - Data Conflict
          > Highlights mismatches between user-provided data and API-verified data.Focus: Address mismatches (e.g., `locationAddress`, `locationCity`) or significant differences in entity/contact details.  Set proposedContactStatus to 0 and flag as "Data Conflict".  Add proposedContactNotes to describe the specific discrepancy (e.g., "Mismatch between user-provided city and API-verified city").
          > 
        - Duplicate Contact
          > Flags entries as duplicates when a matching contact already exists for the same entity and location.Criteria: Matching `firstName`, `lastName`, `email`, and `jobTitle` within the same `entityID` and `locationID`.  Set proposedContactStatus to 0 and flag as "Duplicate Contact".  Add proposedContactNotes to provide details of the existing contact (e.g., "The contact already exists under the same entity and location").
          > 
      - Acceptable Data
        > If all the data is provided and correct, we create a scenario that describes how the database will be updated.  All statuses (entity, contact, location) must be `1` to indicate complete, verified, and acceptable data.  Acceptable data describes a scenario for database updates, such as creating or associating new entities, locations, or contacts.
        > 
        - New Entity, New Location, New Contact
          > Automatically creates a new entity and associated location if they don't exist, along with a new contact.
        - Existing Entity, New Location, New Contact
          > Creates a new location for an existing entity and adds a new contact.
        - Existing Entity, Existing Location, New Contact
          > Adds a contact to an already existing location for an existing entity.
    - Database Tables Details #notcomplete
      > The contact details are drawn from the tblEntities, tblLocations, and tblContacts tables. Each table requires certain fields to be filled to create or update records when entering a new contact.
      > 
      - Entity Information
        > Details required to create an Entity in the database.
        > 
        - Entity ID
          > The primary key for the entity. This is auto-generated.
        - Entity Name
          > The name of the entity (e.g., company or organization).
        - Entity Location ID
          > The ID of the primary location of the entity (links to the tblLocations table).
        - Entity Note
          > Optional notes about the entity.
        - Entity Type
          > Defines whether the entity is a 0 = Company, 1 = Customer, 2 = Vendor, or 3 = Jurisdiction.
        - Entity Is Not Valid
          > This will be set to 0 for valid entities.
      - Location Information
        > Requirements for the location table.
        - Location ID
          > The primary key for the location. This is auto-generated.
        - Location Entity ID
          > The ID of the entity associated with this location (links to the tblEntities table).
        - Location Name
          > The unique name for the location (default: the entity name).
        - Location Place ID
          >  The Google Place_ID for the location.
        - Location Latitude
          > Latitude coordinate of the location.
        - Location Longitude
          >  Longitude coordinate of the location.
        - Location Address
          > The address of the location (street, number).
        - Location Address Suite
          > The suite number or apartment number (optional).
        - Location City
          > The city of the location.
        - Location State
          > The state of the location.
        - Location Zip Code
          > The postal code of the location.
        - Location Parcel Number
          >  Parcel number of the location (optional).
        - Location Is Billing
          > Set to 0 for false, 1 for true if the location is the billing address.
        - Location Note
          > Any additional notes about the location (optional).
        - Location Is Not Valid
          > Always set to 0 for valid locations.
      - Contact Information
        > Needed for the contact.
        > 
        - Contact ID
          > The primary key for the contact. This is auto-generated.
        - Contact Entity ID
          > The ID of the entity associated with this contact (links to the tblEntities table).
        - Contact Salutation
          > Salutation of the contact (0 = Ms., 1 = Mr.).
        - Contact First Name
          > The first name of the contact.
        - Contact Last Name
          > The last name of the contact.
        - Contact Title
          > The job title of the contact (e.g., Project Manager, Supervisor).
        - Contact Is Billing
          > Set to 0 for false, 1 for true if the contact is a billing contact.
        - Contact Location ID
          > The ID of the location associated with this contact (links to the tblLocations table).
        - Contact Primary Phone
          > The primary phone number of the contact.
        - Contact Primary Phone Extension
          > The extension of the primary phone (optional).
        - ContactSecondaryPhone
          > The secondary phone number (optional).
        - Contact Email
          > The email address of the contact.
        - Contact Note
          > Any additional notes about the contact (optional).
        - Contact Is Not Valid
          > Set to 0 for valid contacts.
      - Specific Entry Requirements
        > Information needed to properly create a contact
        - Entity Information
          > Entity name, type, and optional notes.
        - Location Information
          > Location name, address, latitude, longitude, and billing status.
        - Contact Information
          > Contact name, salutation, job title, phone numbers, and email.
          > Billing contact status, if applicable.
    - Data Categories
      > The different type of data provided.
      > 
      - Parsed Data (User-Provided Data)
        > Source: Raw input from the user, parsed by the AI or input processing logic.
        > Purpose: Serves as the initial dataset to verify and cross-check against other sources.
        > Challenges: May contain inconsistencies, missing fields, or formatting errors.
      - API Data (Verified Data)
        > Source: External API responses (e.g., Google Address Validation API).
        > Purpose: Used to validate and enhance the parsed data, such as verifying addresses or extracting geographic components.
        > Challenges: API responses may differ from the parsed data, lack context, or omit critical components (e.g., suite information).
      - Database Data (Existing Data)
        > Source: Database queries that return information about existing entities, locations, or contacts.
        > Purpose: Used to identify duplicates, verify the existence of entities or locations, and establish relationships between data components.
        > Challenges: Database data may be outdated, incomplete, or formatted differently from the parsed and API data.
    - Helper Notes #notcomplete 
      > Helper notes are phrases or keywords included in the raw text to guide the AI in assigning specific attributes. Examples include phrases like "This contact is a jurisdiction," "billing contact," or "He knows Bob in Accounting." These notes help ensure that critical details are captured accurately in the final parsed data.
    - Review Process #notcomplete
      > The server-side code generates a JSON object containing parsed contact details, which will be prefilled in a modal form for the user to review. The form will allow users to view, edit, and confirm or reject each proposed contact entry.
      > 
      - Modal Page Overview
        > The review form will look similar to the existing contact entry form (as shown in the attachment). Each field will be editable so the user can make changes before accepting or rejecting the proposed contact.
        - Entity
          >  Entity name, selected from a dropdown list or auto-filled if detected.
        - Location Name
          > Editable field for the location's name.
        - Location Address
          > Editable address field.
        - Contact Name
          > Editable field for the contact’s full name, divided into First and Last Name.
        - Title
          > Editable field for the contact’s job title.
        - Phone Number
          > Editable field for the primary and secondary contact numbers.
        - Email
          > Editable field for the contact's email.
        - Billing Contact
          > Checkbox indicating if the contact is a billing contact.
        - Notes
          > Editable notes about the contact.
      - Pagination and Navigation
        > Pagination and Navigation
      - Accept/Reject Options
        > The user will review and choose to accept or reject the proposed contact.
        > 
        - Accept
          > If the user accepts a proposed contact, the details will be confirmed and prepared for insertion into the database.
        - Reject
          > If the user rejects the proposed contact, it will be removed from the current JSON object, and the next proposed contact will be shown.
      - Final Review and Updates
        > After reviewing all the proposed contacts, the JSON object will be updated with any accepted contacts, and any rejected contacts will be discarded.  The user can accept multiple contacts in a session, but each contact must be reviewed before it is added to the system.
        > 
      - Adding New Contacts
        > If new bulk contacts are added while there are still pending contacts to be reviewed, the new contacts will be appended to the existing JSON object. This allows for continuous processing without losing any pending entries.
  - Single Session Enforcement #notcomplete 
    > The site has a login / logout architecture that maintains a clean and consistent user activity log. 
    > 
  - Code Guidlines
    > Here they are
    - 1. Define the Instructions as Persistent Guidelines
    - Log Messages
    - Header Comment:
      - Every log message should be preceded by a comment beginning with // Log Message, followed by a parenthetical description in Title Case.
      - The parenthetical description should match the log message content (second argument in logMessage()).
    - Comment Format:
      - Example:
      - php
      - Copy code
      - // Log Message (Processing Contact)
      - logMessage('S', "Processing Contact #" . $index, null, false, null);
    - Title Case Rules:
      - Capitalize main words in the description, avoiding excessive capitalization.
    - Variable Assignment Comments
    - Always Use 'Assign':
      - Start each comment with // Assign [Variable Name] to describe what is being assigned.
      - Example:
      - php
      - Copy code
      - // Assign Parsed Contact
      - $parsedContact = processContactData($rawData);
    - Follow with a Brief Description:
      - Add a concise narrative explanation after the code, separated by a comment:
      - php
      - Copy code
      - // Assign Parsed Contact
      - $parsedContact = processContactData($rawData); // Process and parse raw contact data
    - 2. Embed These Guidelines in Context for Consistency
    - You can request that the AI:
    - Use the Guidelines by Default:
      - Incorporate these as default practices whenever coding examples are generated.
    - Include the Preferences in the User Profile:
      - Add the instructions as coding preferences in the AI's persistent user profile to ensure adherence across sessions.
    - 3. Example Context Update for AI
    - Here’s how you can relay this information to the AI for future use:
    - Coding Preferences:
    - Log Messages: Use // Log Message as the header for all log statements. Include a parenthetical description in Title Case, matching the log message content.
    - Variable Assignments: Use // Assign [Variable Name] as the comment for variable assignments, followed by a brief narrative description after the code.
    - 4. Test the Implementation
    - To ensure these guidelines are consistently followed:
    - Provide a small test task with specific requirements.
    - Evaluate whether the generated code adheres to the guidelines.
    - Adjust the instructions as needed for clarity or specificity.
  - Tasks Overview #notcomplete
    > Organize and manage tasks efficiently by storing them in server folders with corresponding text files for details, notes, schedule dates, and reminders.
    - Standard Tasks #notcomplete
      > Routine tasks aligned with the mission, scheduled for every workday, and categorized by their nature. Folders will be created with task ID numbers, and notes will be stored in text files within each folder. Users are responsible for rescheduling these tasks daily; a cron job will automatically reschedule them to the next workday if missed.
      - Start of Day Tasks (SOD)
        > Routine checks and preparations at the start of the workday.
        > 
        - Schedule Date
          > Scheduled for the start of every workday.
        - Reminder
          > Notification set for the beginning of office hours each day.
        - Notes
          > All notes and progress updates stored in 001_details.txt within the folder.
        - Rescheduling
          > User responsibility; auto-rescheduled by cron job if missed.
      - End of Day Tasks (EOD)
        > Summary and wrap-up activities at the end of the day.
        - Schedule Date
          > Scheduled for the end of every workday.
        - Reminder
          > Notification set for 30 minutes before the end of office hours each day.
        - Notes
          > All notes and progress updates stored in 002_details.txt within the folder.
        - Rescheduling
          > User responsibility; auto-rescheduled by cron job if missed.
    - Special Projects #notcomplete
      > User-initiated projects focusing on growth, innovation, or long-term goals. Each project has a dedicated folder on the server for all related files and notes. Users are responsible for setting and rescheduling project dates; a cron job will reschedule them to the next day if missed.
      - Schedule Date
        > User-defined date and time.
      - Reminder
        > Notification set based on the user-defined schedule.
      - Notes
        > All notes, progress updates, and next steps stored in 003_details.txt. Additional files related to the project will be stored in this folder.
      - Rescheduling
        > User responsibility; auto-rescheduled by cron job if missed.
    - Order & Application Tasks #notcomplete
      > Tasks related to specific orders or sign permit applications, with corresponding folders and text files for notes, details, and schedules. Users are responsible for rescheduling; a cron job will reschedule them to the next day if missed.
      - OrderTask
        > A task which is created from the order.
        - Schedule Date
          > User-defined date and time.
        - Reminder
          > Notification set based on the user-defined schedule.
        - Notes
          > All notes, progress updates, and next steps stored in 004_details.txt. Order-related files are saved in the same folder.
        - Rescheduling
          > User responsibility; auto-rescheduled by cron job if missed.
      - Application Task
        > Task related to a specific sign permit application (Application ID: 54321).
        - Reminder
          > Notification set based on the user-defined schedule.
        - Notes
          > All notes, progress updates, and next steps stored in 005_details.txt. Application-related files are saved in the same folder.
        - Rescheduling
          > User responsibility; auto-rescheduled by cron job if missed.
    - Task Management Implementation #notcomplete
      > Steps to implement the task management system with global variables, server folders, scheduling, and automatic rescheduling:
      - Step 1
        > Create a global variable structure to manage task details, schedule dates, and reminders.
        - Subtask
          > Ensure that the variable tracks task ID, description, notes, schedule date, and reminders.
      - Step 2
        > Set up server folders for each task using their unique ID numbers.
        - Subtask
          > Automatically generate folders and details.txt files for notes when a new task is created.
      - Step 3
        > Implement scheduling and reminders for tasks.
        - Subtask
          > Set up automatic scheduling and reminder notifications for Standard Tasks (SOD/EOD) and user-defined schedules for Special Projects and Order/Application Tasks.
      - Step 4
        > Integrate file storage within task folders.
        - Subtask
          > Allow users to upload and store files within the appropriate task folder for easy access and organization.
      - Step 5
        > Set up a cron job for automatic rescheduling.
        - Subtask
          > The cron job will check daily for any missed tasks and automatically reschedule them to the next workday
