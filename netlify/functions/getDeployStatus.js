/// netlify/functions/getDeployStatus.js
export const handler = async () => {
  const siteId = "aabb6211-df65-4b26-9afe-f937e5824de7"; // ✅ Your actual Netlify Site ID
  const accessToken = process.env.NETLIFY_ACCESS_TOKEN;

  try {
    const res = await fetch(`https://api.netlify.com/api/v1/sites/${siteId}/deploys`, {
      headers: {
        Authorization: `Bearer ${accessToken}`
      }
    });

    if (!res.ok) {
      return {
        statusCode: res.status,
        body: JSON.stringify({ error: "Failed to fetch deploys" })
      };
    }

    const data = await res.json();
    const latest = data.find(deploy => deploy.context === "production");

    const siteMeta = {
      siteVersion: latest?.commit_ref || "unknown",
      lastDeployNote: latest?.title || latest?.branch || "No commit message",
      lastDeployTime: latest?.published_at || null,
      deployState: latest?.state || "unknown",
      deployIsLive: latest?.state === "ready"
    };

    return {
      statusCode: 200,
      headers: { "Access-Control-Allow-Origin": "*" },
      body: JSON.stringify(siteMeta)
    };
  } catch (err) {
    return {
      statusCode: 500,
      body: JSON.stringify({ error: "Server error", details: err.message })
    };
  }
};
