import { FastifyRequest, FastifyReply, RouteGenericInterface } from "fastify";
import { Readable } from "node:stream";
import { ReadableStream } from "node:stream/web";
import { SalesforceConfig, ServerSentEventRequest } from "../types";

export async function handleSSEConnection(
  salesforceConfig: SalesforceConfig,
  request: FastifyRequest<ServerSentEventRequest>,
  reply: FastifyReply
) {
  const { token } = request.query;
  const { esDeveloperName, scrtUrl, orgId } = salesforceConfig;

  const sseResponse = await fetch(`https://${scrtUrl}/eventrouter/v1/sse`, {
    headers: {
      Accept: "text/event-stream",
      Authorization: `Bearer ${token}`,
      "X-Org-Id": orgId,
    },
  });

  if (!sseResponse.ok) {
    throw new Error(
      `Failed to connect to Salesforce SSE: ${sseResponse.statusText}`
    );
  }

  if (!sseResponse.body) {
    throw new Error("No response body received");
  }

  const streamableResponse = {
    status: 200,
    headers: {
      "Content-Type": "text/event-stream",
      "Cache-Control": "no-cache",
      Connection: "keep-alive",
      "Access-Control-Allow-Origin": "http://localhost:5173",
      "Access-Control-Allow-Credentials": "true",
    },
    body: sseResponse.body,
  };

  const nodeStream = Readable.fromWeb(
    streamableResponse.body as unknown as ReadableStream
  );

  reply.raw.writeHead(200, streamableResponse.headers);

  request.raw.on("close", () => nodeStream.destroy());
  nodeStream.on("error", () => {
    nodeStream.destroy();
    reply.raw.end();
  });

  nodeStream.pipe(reply.raw);
}
