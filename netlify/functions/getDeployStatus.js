// netlify/functions/getDeployStatus.js

// #region 🛰️ Netlify Deployment Status API
export const handler = async () => {

  // #region 🔐 Config & Auth
  const siteId = "aabb6211-df65-4b26-9afe-f937e5824de7"; // ✅ Skyesoft Netlify Site ID
  const accessToken = process.env.NETLIFY_ACCESS_TOKEN;  // 🔐 Stored securely in ENV
  // #endregion

  // #region ⚙️ Execute Main Logic
  const getDeployMeta = async () => {
    // 🌐 Step 1: Fetch full deploy history
    const res = await fetch(`https://api.netlify.com/api/v1/sites/${siteId}/deploys`, {
      headers: { Authorization: `Bearer ${accessToken}` }
    });

    if (!res.ok) throw new Error(`Failed to fetch deploys (Status ${res.status})`);

    const data = await res.json();
    const latest = data.find(d => d.context === "production");
    if (!latest) throw new Error("No production deploys found.");

    // 🧾 Step 2: Parse metadata for versioning
    const deployTime = latest.published_at || latest.created_at;
    const commitShort = latest.commit_ref?.substring(0, 7) || "unknown";
    const datePrefix = new Date(deployTime).toISOString().split("T")[0].replace(/-/g, ".");

    return {
      siteVersion: `v${datePrefix}-${commitShort}`,
      lastDeployNote: latest.title || latest.branch || "No commit message",
      lastDeployTime: deployTime,
      deployState: latest.state || "unknown",
      deployIsLive: latest.state === "ready"
    };
  };
  // #endregion

  // #region 📤 Return JSON or Fallback
  try {
    const siteMeta = await getDeployMeta();
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
  // #endregion
};
// #endregion