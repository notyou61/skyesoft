export const handler = async () => {
  const siteId = "aabb6211-df65-4b26-9afe-f937e5824de7"; // ✅ Skyesoft Netlify Site ID
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

    const deployTime = latest.published_at || latest.created_at;
    const commitShort = latest.commit_ref ? latest.commit_ref.substring(0, 7) : "unknown";
    const datePrefix = new Date(deployTime).toISOString().split("T")[0].replace(/-/g, ".");

    const siteMeta = {
      siteVersion: `v${datePrefix}-${commitShort}`,
      lastDeployNote: latest.title || latest.branch || "No commit message",
      lastDeployTime: deployTime,
      deployState: latest.state || "unknown",
      deployIsLive: latest.state === "ready"
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
// Tip updated just to trigger deploy
//"Double-check before pushing to production."