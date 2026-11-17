# FILE PURPOSE
# File   : forecasting/chronos_service.py
# Feature: Forecasting
# Purpose: Python web service that runs Amazon Chronos to forecast each client's shipment volume;
#          called by ForecastingController.

"""
AstraTrack - Customer Volume Forecasting Service (Amazon Chronos)
=================================================================

What this file does
-------------------
Runs a small local web service (http://127.0.0.1:8001) that receives each
customer's past monthly shipment counts and predicts the next months using
Amazon Chronos-Bolt, a pretrained time-series forecasting model.

Why Chronos
-----------
* Pretrained on a large amount of real-world time series, so it works "zero-shot":
  no training on our own data is needed.
* Free and open source (Apache 2.0): https://github.com/amazon-science/chronos-forecasting
* Runs offline on a normal CPU after the first model download; customer data
  never leaves the server.

How it is used
--------------
Laravel (app/Http/Controllers/Admin/ForecastingController.php) sends:
    POST /forecast
    {"horizon": 3, "series": {"12": [4, 6, 5, 9], "15": [1, 0, 2, 3]}}
and receives, per customer, three values per future month:
    low (10th percentile), median (most likely), high (90th percentile)

Run it
------
    pip install -r forecasting/requirements.txt
    python forecasting/chronos_service.py
"""

from typing import Dict, List

import torch
import uvicorn
from chronos import BaseChronosPipeline
from fastapi import FastAPI
from pydantic import BaseModel, Field

MODEL_NAME = "amazon/chronos-bolt-small"  # ~190 MB, fast on CPU
QUANTILES = [0.1, 0.5, 0.9]               # low, median, high

app = FastAPI(title="AstraTrack Chronos Forecasting")

# Load the model once at startup (downloaded from Hugging Face the first time).
pipeline = BaseChronosPipeline.from_pretrained(MODEL_NAME, device_map="cpu", torch_dtype=torch.float32)


class ForecastRequest(BaseModel):
    horizon: int = Field(3, ge=1, le=12)          # months to predict
    series: Dict[str, List[float]]                 # client_id -> monthly counts (oldest first)


@app.get("/health")
def health():
    return {"status": "ok", "model": MODEL_NAME}


@app.post("/forecast")
def forecast(req: ForecastRequest):
    ids = list(req.series.keys())
    if not ids:
        return {"model": MODEL_NAME, "forecasts": {}}

    contexts = [torch.tensor(req.series[i], dtype=torch.float32) for i in ids]

    # quantiles shape: [customers, horizon, len(QUANTILES)]
    quantiles, _mean = pipeline.predict_quantiles(
        contexts, prediction_length=req.horizon, quantile_levels=QUANTILES
    )

    results = {}
    for row, client_id in enumerate(ids):
        q = quantiles[row].clamp(min=0)  # shipment counts cannot be negative
        results[client_id] = {
            "low": [round(float(v), 2) for v in q[:, 0]],
            "median": [round(float(v), 2) for v in q[:, 1]],
            "high": [round(float(v), 2) for v in q[:, 2]],
        }
    return {"model": MODEL_NAME, "forecasts": results}


if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=8001)
