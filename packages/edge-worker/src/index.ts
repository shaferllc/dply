import { handleRequest, type Env } from './handler';

export default {
  fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    return handleRequest(request, env, ctx);
  },
};

export { handleRequest, type Env, type HostMapEntry } from './handler';
