// sseServer.js - Sample Server-Sent Events with Global Stream Payload

const express = require('express');
const cors = require('cors');
const app = express();
const PORT = 4321;

app.use(cors());

let streamCount = 0;

app.get('/sse', (req, res) => {
  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');
  res.flushHeaders();

  const interval = setInterval(() => {
    streamCount++;
    const now = Math.floor(Date.now() / 1000);

    const data = {
      meta: {
        streamCount,
        version: '1.0.3',
        build: '2025-06-18T13:00:00Z',
        status: 'active'
      },
      user: {
        contactId: 101,
        lastLogin: '2025-06-17T08:12:45Z'
      },
      time: {
        timeDate: {
          currentUnixTime: now,
          currentDate: new Date(now * 1000).toISOString().split('T')[0],
          currentYearTotalDays: 365,
          currentYearDayNumber: 59,
          currentYearDaysRemaining: 306,
          currentMonthNumber: '2',
          currentWeekdayNumber: '5',
          currentDayNumber: '28',
          currentHour: '05',
          timeOfDayDescription: 'morning',
          timeZone: 'America/Phoenix',
          UTCOffset: -7,
          isWorkday: 1,
          isWeekday: 1,
          isWeekend: 0,
          isHoliday: 0,
          daylightStartEnd: {
            start: '06:55:55',
            end: '18:25:08'
          },
          defaultLocation: {
            latitude: '33.448376',
            longitude: '-112.074036',
            solarZenithAngle: 90.83,
            UTCOffset: -7
          },
          dayBounds: {
            startUnix: now - (now % 86400),
            endUnix: now - (now % 86400) + 86399
          }
        }
      },
      site: {
        name: 'Skyesoft',
        tag: 'SKyesoft',
        pageHeader: 'Page Header Test',
        startUnix: 1679295600,
        cron: {
          count: 788524,
          lastJob: now - 7,
          nextJob: now + 53,
          sinceLast: 7,
          untilNext: 53
        }
      }
    };

    res.write(`data: ${JSON.stringify(data)}\n\n`);
  }, 1000);

  req.on('close', () => {
    clearInterval(interval);
    res.end();
  });
});

app.listen(PORT, () => console.log(`✅ SSE server running at http://localhost:${PORT}/sse`));